<?php

namespace App\Commands\Meet;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
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
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class MeetInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithMeet, LaraKubeOutput, ManagesToolFirewallPorts, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

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
        $kubectl = Kubectl::forContext($context)->prefix();
        $host = $this->resolveToolHost(SharedClusterService::MEET, ClusterTool::MEET, $env, $kubectl);
        // Every tool's instance identifier is a real, host-derived slug now —
        // Meet included, even though its hostPort-bound RTC ports make a
        // genuine second instance impossible on one node regardless of
        // naming (see ClusterTool::supportsMultipleInstances()'s docblock).
        $names = ToolInstance::forHost(ClusterTool::MEET, $host);
        $instance = $names->instance;
        $ns = $names->namespace();
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
        $registry = $this->readMeetKeys($kubectl, $names);
        $this->withSpin('Syncing consumer keys...', function () use ($kubectl, $names, &$registry): void {
            $registry = $this->writeMeetKeys($kubectl, $names, $registry);
        });

        $manifest = view('k8s.meet.livekit', [
            'host' => $host,
            'instance' => $instance,
            'consumers' => $registry,
            'hostPort' => ! $this->option('no-host-port'),
        ])->render()
            ."\n---\n"
            .view('k8s.meet.ingress', [
                'host' => $host,
                'instance' => $instance,
                'isLocal' => $env === 'local',
                'proxied' => $this->resolveProxied($env === 'local'),
                'vpnOnly' => $vpnOnly,
                'jwtWired' => $this->isMeetBridgeDeployed($kubectl, $ns),
            ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-meet.yaml');
        file_put_contents($tmp, $manifest);

        $deploymentName = $names->deployment();

        // runStreaming() hands back an EXIT CODE, and withSpin() reads its
        // callback's return as a success flag — so a non-zero code rendered a
        // tick and the command went on to announce the SFU was live. LiveKit
        // binds its RTC ports with hostPort, so the most likely failure here
        // is a second pod that can never schedule; reporting that as success
        // is the one thing that must not happen.
        $rolledOut = $this->withSpin(
            'Applying LiveKit (Meet) manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $deploymentName, 180),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            $this->laraKubeError(
                'LiveKit did not become Ready. Its RTC ports are bound with hostPort, so only one '
                ."LiveKit pod can run per node — check `kubectl get pods -n {$ns}` for one stuck Pending.",
            );

            return 1;
        }

        $this->registerDeployedTool(ClusterTool::MEET, $kubectl, $host, $instance);

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
}
