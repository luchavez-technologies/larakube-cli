<?php

namespace App\Commands\Uptime;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithTraefik;
use App\Traits\InteractsWithUptime;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class UptimeInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithTraefik, InteractsWithUptime, LaraKubeOutput, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'uptime:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted, like plex:init. A non-local env prompts for + persists the Uptime Kuma host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for Uptime Kuma (example.com → status.example.com; status.example.com used as-is)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Deploy the cluster-wide Uptime Kuma status page stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployUptime();
    }

    protected function deployUptime(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->uptimeKubectl($context);
        $ns = $this->uptimeNamespace();

        $host = $this->resolveUptimeHost($env);

        if ($env === 'local') {
            $config = $this->getProjectConfig(getcwd());
            if ($config) {
                $this->withSpin('Syncing local TLS certificates...', function () use ($config) {
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
            'vpnOnly' => $vpnOnly,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-uptime.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Uptime Kuma manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Uptime Kuma...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/uptime-kuma -n {$ns} --timeout=120s",
            130,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Uptime Kuma stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Uptime Kuma URL:</>            <fg=blue>https://{$host}</>");
        $this->newLine();

        $this->showUptimeGuide($env, $this->getProjectConfig(getcwd()));

        return 0;
    }

    /**
     * Resolve the Uptime Kuma ingress host for this install.
     */
    protected function resolveUptimeHost(string $env): string
    {
        $service = SharedClusterService::UPTIME_KUMA;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveUptimeHostReadOnly('local', null);
        }

        return $this->promptForCloudUptimeHost($service, $env);
    }

    /**
     * Decide which environment this install targets.
     */
    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::UPTIME);
    }

    /**
     * Prompt for (and persist) a non-local Uptime Kuma host.
     */
    protected function promptForCloudUptimeHost(SharedClusterService $service, string $env): string
    {
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $existing = $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
        if ($existing) {
            return $existing;
        }

        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        $default = ($config && $webHost) ? $config->getSharedServiceHost($service, $env) : '';

        $host = text(
            label: "What host should {$service->label()} use in '{$env}'?",
            placeholder: $default !== '' ? $default : 'e.g. status.example.com',
            default: $default,
            required: true,
            hint: 'Point this DNS at the cluster and add TLS like any other ingress host.',
        );

        if ($config) {
            $config->setHost($env, $service->value, $host);
            $config->saveToFile($projectPath);
            $this->laraKubeInfo("Saved {$service->label()} host for '{$env}' to .larakube.json");
        }

        return $host;
    }
}
