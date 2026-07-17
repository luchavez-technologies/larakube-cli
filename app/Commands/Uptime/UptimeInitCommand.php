<?php

namespace App\Commands\Uptime;

use App\Data\ConfigData;
use App\Enums\SharedClusterService;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithTraefik;
use App\Traits\InteractsWithUptime;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class UptimeInitCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithTraefik, InteractsWithUptime, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'uptime:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted, like plex:init. A non-local env prompts for + persists the Uptime Kuma host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--env=      : Legacy alias for the environment argument}
        {--domain=   : Raw override for the Uptime Kuma cluster domain (e.g. example.com → status.example.com); skips the prompt}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--remove    : Tear down the Uptime Kuma stack from larakube-shared}';

    protected $description = 'Deploy the cluster-wide Uptime Kuma status page stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->option('remove')
            ? $this->removeUptime()
            : $this->deployUptime();
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

    protected function removeUptime(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->uptimeKubectl($context);
        $ns = $this->uptimeNamespace();

        if (! $this->removeResources('Removing Uptime Kuma...',
            "{$kubectl} delete deployment,svc,ingress,pvc"
            .' uptime-kuma uptime-kuma-storage'
            ." -n {$ns} --ignore-not-found")) {
            $this->laraKubeError('Failed to remove Uptime Kuma resources — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $this->laraKubeInfo('Uptime Kuma removed from larakube-shared.');

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
        $explicit = (string) ($this->argument('environment') ?: $this->option('env') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        if ($this->option('no-interaction') || $this->option('domain')) {
            return 'local';
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $envs = $config ? array_merge(['local'], $config->getCloudEnvironments()) : ['local'];

        return select(
            label: 'Which environment is this Uptime Kuma install for?',
            options: array_combine($envs, $envs),
            default: 'local',
            hint: 'Local uses your dev TLD; a cloud env asks for + persists the Uptime Kuma host.',
        );
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
