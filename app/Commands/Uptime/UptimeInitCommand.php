<?php

namespace App\Commands\Uptime;

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithTraefik;
use App\Traits\InteractsWithUptime;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class UptimeInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithTraefik, InteractsWithUptime, LaraKubeOutput, RefusesUnshippedTools, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

    protected $signature = 'uptime:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted, like plex:init. A non-local env prompts for + persists the Uptime Kuma host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for Uptime Kuma (example.com → status.example.com; status.example.com used as-is)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the cluster-wide Uptime Kuma status page stack into larakube-shared';

    public function handle(): int
    {
        if ($this->refuseUnshippedTool(ClusterTool::UPTIME)) {
            return 1;
        }

        $this->renderHeader();

        return $this->deployUptime();
    }

    protected function deployUptime(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->uptimeKubectl($context);
        $ns = $this->uptimeNamespace();

        $host = $this->resolveToolHost(SharedClusterService::UPTIME_KUMA, ClusterTool::UPTIME, $env, $kubectl);

        if ($env === 'local') {
            $config = $this->getProjectConfig(getcwd());
            if ($config) {
                $this->withSpin('Syncing local TLS certificates...', function () use ($config): void {
                    $this->refreshTraefikCerts($config->getName(), $config->getLocalTld());
                });
            }
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::UPTIME, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $manifest = view('k8s.uptime.shared', [
            'host' => $host,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'vpnOnly' => $vpnOnly,
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-uptime.yaml');
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Uptime Kuma manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, 'uptime-kuma', 120),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::UPTIME, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Uptime Kuma stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Uptime Kuma URL:</>            <fg=blue>https://{$host}</>");
        $this->newLine();

        $this->showUptimeGuide($env, $this->getProjectConfig(getcwd()));

        return 0;
    }

    /**
     * Decide which environment this install targets.
     */
    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::UPTIME);
    }
}
