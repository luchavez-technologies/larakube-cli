<?php

namespace App\Commands\Meet;

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithMeet;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesToolFirewallPorts;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class MeetInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithMeet, LaraKubeOutput, ManagesToolFirewallPorts, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'meet:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Meet (example.com → meet.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--no-host-port : Skip hostPort on LiveKit — use on managed K8s with a real LoadBalancer}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the shared LiveKit SFU (Meet) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployMeet();
    }

    protected function deployMeet(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->meetKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::MEET, ClusterTool::MEET, $env, $kubectl);
        $ns = $this->meetNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->assertVpnOnlySupported(ClusterTool::MEET)) {
            return 1;
        }

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::MEET, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // Re-read rather than regenerate: consumers already hold these keys in
        // their .env / lk-jwt Deployment, so a re-run must not invalidate them.
        $registry = $this->readMeetKeys($kubectl, $ns);
        $this->withSpin('Syncing consumer keys...', function () use ($kubectl, $ns, &$registry) {
            $registry = $this->writeMeetKeys($kubectl, $ns, $registry);
        });

        $manifest = view('k8s.meet.livekit', [
            'host' => $host,
            'consumers' => $registry,
            'hostPort' => ! $this->option('no-host-port'),
        ])->render()
            ."\n---\n"
            .view('k8s.meet.ingress', [
                'host' => $host,
                'isLocal' => $env === 'local',
                'proxied' => $this->resolveProxied($env === 'local'),
                'vpnOnly' => $vpnOnly,
                'jwtWired' => $this->isMeetChatWired($kubectl, $ns),
            ])->render();

        $tmp = sys_get_temp_dir().'/larakube-meet.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying LiveKit (Meet) manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for LiveKit (Meet)...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/meet-livekit -n {$ns} --timeout=180s",
        ));

        $this->registerDeployedTool(ClusterTool::MEET, $kubectl, $host);

        // On a cloud VPS, punch LiveKit's raw UDP/TCP ports through both
        // firewall layers (DO cloud edge + host UFW) — klipper binds them via
        // hostPort, but both default-deny, so media silently never connects.
        $this->openToolPorts(SharedClusterService::MEET, $env);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ LiveKit (Meet) is live.');
        $this->newLine();
        $this->line("  <fg=gray>Signaling:</>   <fg=blue>wss://{$host}</>");
        $this->line('  <fg=gray>Media:</>       UDP 7882 (TCP 7881 fallback)');
        $this->newLine();

        $consumers = $this->meetConsumers($registry);

        if ($consumers === []) {
            $this->line('  <fg=gray>No consumers wired yet.</>');
            $this->line("  <fg=gray>Connect Matrix:</>  <fg=blue>larakube meet:wire {$env} --tool=chat</>");
        } else {
            $this->line('  <fg=gray>Wired consumers:</> <fg=blue>'.implode(', ', array_keys($consumers)).'</>');
        }
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::MEET);
    }

    /** Is the Matrix bridge deployed? Drives the /jwt route in the ingress. */
    protected function isMeetChatWired(string $kubectl, string $ns): bool
    {
        return trim(Process::run("{$kubectl} get deployment meet-lk-jwt -n {$ns} --no-headers --ignore-not-found")->output()) !== '';
    }
}
