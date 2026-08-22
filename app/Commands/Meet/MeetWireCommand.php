<?php

namespace App\Commands\Meet;

use App\Enums\ClusterTool;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMeet;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesMeetWireTarget;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Connect a consumer to the shared LiveKit SFU.
 *
 * For chat this means three things that have to happen together: a key pair
 * minted in the meet-keys registry, the Matrix↔LiveKit bridge deployed at
 * meet.<domain>/jwt, and Synapse's calling config pointed at that bridge.
 * Doing any one alone leaves calling broken in a way that looks like a media
 * fault, so this command owns all three.
 */
class MeetWireCommand extends Command
{
    use InteractsWithChat, InteractsWithClusterContext, InteractsWithMeet, InteractsWithToolRegistry, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesMeetWireTarget, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'meet:wire
        {environment=local : Environment whose Meet install to wire}
        {--tool= : The tool to connect to Meet (prompts when omitted)}
        {--context= : Target a specific kube-context}';

    protected $description = 'Connect a tool to the shared LiveKit SFU (Meet)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');

        $context = $this->resolveMeetContext($env);
        $kubectl = $this->meetKubectl($context);
        $ns = $this->meetNamespace();

        if (! $this->isMeetInstalled($kubectl, $ns)) {
            $this->laraKubeError("Meet is not installed on this cluster. Run `larakube meet:init {$env}` first.");

            return 1;
        }

        if ($this->resolveMeetWireTarget($kubectl, 'wire') === null) {
            return 1;
        }

        $meetHost = $this->getToolHost($kubectl, ClusterTool::MEET);
        $chatHost = $this->getToolHost($kubectl, ClusterTool::CHAT);

        if ($meetHost === null || $chatHost === null) {
            $this->laraKubeError('Could not resolve the Meet and Chat hosts from the tool registry — re-run their init commands.');

            return 1;
        }

        $jwtUrl = "https://{$meetHost}/jwt";

        // 1. Mint chat's own key pair. Re-running keeps the existing one, so the
        //    bridge's credentials survive a re-wire.
        $registry = $this->readMeetKeys($kubectl, $ns);
        $registry = $this->allocateMeetKey($registry, 'chat', 'matrix-');
        // withSpin() proxies Laravel Zero's task(), which returns a success
        // bool — never the callback's value. Hand the registry back by ref.
        $this->withSpin('Allocating a LiveKit key for Chat...', function () use ($kubectl, $ns, &$registry): void {
            $registry = $this->writeMeetKeys($kubectl, $ns, $registry);
        });

        $creds = $registry['chat'];

        // 2. Deploy the bridge, then re-apply LiveKit so it actually loads the
        //    new key — the config-checksum changes, forcing the rollout.
        $ok = $this->withSpin('Deploying the Matrix bridge...', function () use ($kubectl, $meetHost, $chatHost, $creds) {
            $manifest = view('k8s.meet.lk-jwt', [
                'meetHost' => $meetHost,
                'chatHost' => $chatHost,
                'livekitApiKey' => $creds['key'],
                'livekitApiSecret' => $creds['secret'],
            ])->render();

            $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
            $tmp = $temporaryDirectory->path().'/meet-lkjwt.yaml';
            file_put_contents($tmp, $manifest);
            $result = Process::run("{$kubectl} apply -f {$tmp}");
            $temporaryDirectory->delete();

            return $result->successful();
        });

        if (! $ok) {
            $this->laraKubeError('Failed to deploy the Matrix bridge.');

            return 1;
        }

        if (! $this->reapplyMeet($kubectl, $ns, $meetHost, $registry, $env)) {
            return 1;
        }

        // 3. Point Synapse at the bridge and record the wiring so a later
        //    chat:init re-render does not silently drop it.
        if (! $this->wireSynapseCalling($kubectl, $ns, $jwtUrl)) {
            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Team Chat is wired to Meet.');
        $this->newLine();
        $this->line("  <fg=gray>Focus URL:</>  <fg=blue>{$jwtUrl}</>");
        $this->line("  <fg=gray>Rooms:</>      <fg=blue>{$creds['roomPrefix']}*</>");
        $this->newLine();
        $this->line('  <fg=gray>Synapse is restarting — give it a moment before placing a call.</>');
        $this->newLine();

        return 0;
    }

    /** Re-render LiveKit (new key set) and the ingress (now with /jwt). */
    protected function reapplyMeet(string $kubectl, string $ns, string $meetHost, array $registry, string $env): bool
    {
        $ok = $this->withSpin('Reloading LiveKit with the new key...', function () use ($kubectl, $meetHost, $registry, $env) {
            $manifest = view('k8s.meet.livekit', [
                'host' => $meetHost,
                'consumers' => $registry,
                'hostPort' => true,
            ])->render()
                ."\n---\n"
                .view('k8s.meet.ingress', [
                    'host' => $meetHost,
                    'isLocal' => $env === 'local',
                    'jwtWired' => true,
                ])->render();

            $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
            $tmp = $temporaryDirectory->path().'/meet-reapply.yaml';
            file_put_contents($tmp, $manifest);
            $result = Process::run("{$kubectl} apply -f {$tmp}");
            $temporaryDirectory->delete();

            return $result->successful();
        });

        if (! $ok) {
            $this->laraKubeError('Failed to reload LiveKit with the new key.');
        }

        return $ok;
    }

    /**
     * Rewrite Synapse's calling block in place and restart it. The chat-meet
     * Secret is what chat:init reads back on re-run.
     */
    protected function wireSynapseCalling(string $kubectl, string $ns, string $jwtUrl): bool
    {
        $ok = true;

        $this->withSpin('Pointing Synapse at the Meet bridge...', function () use ($kubectl, $ns, $jwtUrl, &$ok): void {
            Process::run(
                "{$kubectl} create secret generic chat-meet -n {$ns} "
                .'--from-literal=jwt-url='.escapeshellarg($jwtUrl).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            $raw = Process::run(
                "{$kubectl} get secret chat-synapse-config -n {$ns} -o jsonpath='{.data.homeserver\.yaml}'",
            )->output();

            if (trim($raw) === '') {
                $ok = false;

                return;
            }

            // Read back MAS's public issuer (if chat:init has already
            // activated MAS-native auth) so wiring calling here doesn't
            // clobber Element X's auth-discovery well-known key — the two
            // concerns share one YAML top-level key.
            $masPublicIssuer = $this->readChatWiredMas($kubectl, $ns)['public_issuer'] ?? null;
            $homeserver = $this->renderSynapseCalling((string) base64_decode(trim($raw)), $jwtUrl, $masPublicIssuer ?: null);

            $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
            $tmp = $temporaryDirectory->path().'/homeserver.yaml';
            file_put_contents($tmp, $homeserver);
            $result = Process::run(
                "{$kubectl} create secret generic chat-synapse-config -n {$ns} "
                ."--from-file=homeserver.yaml={$tmp} "
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
            $temporaryDirectory->delete();

            $ok = $result->successful();
            if ($ok) {
                Process::run("{$kubectl} rollout restart deployment/chat-synapse -n {$ns}");
            }
        });

        if (! $ok) {
            $this->laraKubeError('Failed to update Synapse — its config Secret could not be read or written.');
        }

        return $ok;
    }

    protected function resolveMeetContext(string $env): ?string
    {
        $context = (string) $this->option('context') ?: null;
        if ($context !== null) {
            return $context;
        }

        $config = $this->loadProjectConfigIfAny();

        return $config && $env !== 'local' ? $this->environmentContextOrCurrent($config, $env) : null;
    }
}
