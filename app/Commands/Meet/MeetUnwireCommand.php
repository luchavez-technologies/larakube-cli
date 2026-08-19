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
 * Disconnect a consumer from the shared LiveKit SFU.
 *
 * The exact reverse of meet:wire, in the opposite order: Synapse stops
 * advertising the focus first, so no client starts a call against a bridge
 * that is about to disappear.
 */
class MeetUnwireCommand extends Command
{
    use InteractsWithChat, InteractsWithClusterContext, InteractsWithMeet, InteractsWithToolRegistry, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesMeetWireTarget, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'meet:unwire
        {environment=local : Environment whose Meet wiring to remove}
        {--tool= : The tool to disconnect from Meet (prompts when omitted)}
        {--context= : Target a specific kube-context}';

    protected $description = 'Disconnect a tool from the shared LiveKit SFU (Meet)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');

        $context = $this->resolveMeetContext($env);
        $kubectl = $this->meetKubectl($context);
        $ns = $this->meetNamespace();

        if ($this->resolveMeetWireTarget($kubectl, 'unwire') === null) {
            return 1;
        }

        $registry = $this->readMeetKeys($kubectl, $ns);

        $bridgeExists = trim(Process::run(
            "{$kubectl} get deployment meet-lk-jwt -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';

        if (! isset($registry['chat']) && ! $bridgeExists) {
            $this->laraKubeInfo('Team Chat is not wired to Meet — nothing to do.');

            return 0;
        }

        // 1. Stop Synapse advertising the focus before the bridge goes, so no
        //    client starts a call against something that is disappearing.
        if ($this->isChatInstalled($kubectl, $ns) && ! $this->unwireSynapseCalling($kubectl, $ns)) {
            return 1;
        }

        // 2. Tear the bridge down.
        $this->withSpin('Removing the Matrix bridge...', fn () => Process::run(
            "{$kubectl} delete deployment/meet-lk-jwt service/meet-lk-jwt middleware/meet-jwt-stripprefix -n {$ns} --ignore-not-found",
        ));

        // 3. Revoke chat's key and reload LiveKit without it. writeMeetKeys
        //    keeps the bootstrap key, so the SFU still has a valid config even
        //    when this was the only consumer.
        $registry = $this->revokeMeetKey($registry, 'chat');
        // withSpin() returns a success bool, not the callback's value.
        $this->withSpin('Revoking the Chat LiveKit key...', function () use ($kubectl, $ns, &$registry): void {
            $registry = $this->writeMeetKeys($kubectl, $ns, $registry);
        });

        $meetHost = $this->getToolHost($kubectl, ClusterTool::MEET);

        if ($meetHost !== null && $this->isMeetInstalled($kubectl, $ns)) {
            $this->withSpin('Reloading LiveKit without the Chat key...', function () use ($kubectl, $meetHost, $registry, $env): void {
                $manifest = view('k8s.meet.livekit', [
                    'host' => $meetHost,
                    'consumers' => $registry,
                    'hostPort' => true,
                ])->render()
                    ."\n---\n"
                    .view('k8s.meet.ingress', [
                        'host' => $meetHost,
                        'isLocal' => $env === 'local',
                        'jwtWired' => false,
                    ])->render();

                $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
                $tmp = $temporaryDirectory->path().'/meet-unwire.yaml';
                file_put_contents($tmp, $manifest);
                Process::run("{$kubectl} apply -f {$tmp}");
                $temporaryDirectory->delete();
            });
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Team Chat is disconnected from Meet.');
        $this->newLine();
        $this->line('  <fg=gray>Matrix video calling is now unavailable until you re-wire it.</>');
        $this->line("  <fg=gray>Re-connect:</> <fg=blue>larakube meet:wire {$env} --tool=chat</>");
        $this->newLine();

        return 0;
    }

    /** Strip the calling block from homeserver.yaml, drop chat-meet, restart. */
    protected function unwireSynapseCalling(string $kubectl, string $ns): bool
    {
        $ok = true;

        $this->withSpin('Removing the focus from Synapse...', function () use ($kubectl, $ns, &$ok): void {
            $raw = Process::run(
                "{$kubectl} get secret chat-synapse-config -n {$ns} -o jsonpath='{.data.homeserver\.yaml}'",
            )->output();

            if (trim($raw) === '') {
                $ok = false;

                return;
            }

            $homeserver = $this->renderSynapseCalling((string) base64_decode(trim($raw)), null);

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
                Process::run("{$kubectl} delete secret chat-meet -n {$ns} --ignore-not-found");
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
