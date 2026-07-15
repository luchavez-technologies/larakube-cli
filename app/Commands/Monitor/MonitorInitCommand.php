<?php

namespace App\Commands\Monitor;

use App\Data\ConfigData;
use App\Enums\SharedClusterService;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMonitoring;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class MonitorInitCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMonitoring, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'monitor:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted, like plex:init. A non-local env prompts for + persists the Grafana host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--env=      : Legacy alias for the environment argument}
        {--domain=   : Raw override for the Grafana cluster domain (e.g. example.com → grafana.example.com); skips the prompt}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--remove    : Tear down the monitoring stack (Prometheus, Loki, Grafana) from larakube-shared}';

    protected $description = 'Deploy the cluster-wide monitoring stack (Prometheus, Loki, Grafana) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->option('remove')
            ? $this->removeMonitoring()
            : $this->deployMonitoring();
    }

    protected function deployMonitoring(): int
    {
        $kubectl = $this->monitoringKubectl($this->option('context'));
        $ns = $this->monitoringNamespace();

        // Resolve the Grafana ingress host (local dev TLD, or a prompted +
        // persisted host for a non-local --env). Without this, a prod install
        // would come up on grafana.localhost and be unreachable.
        $host = $this->resolveGrafanaHost();

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $password = $this->resolveGrafanaPassword($kubectl, $ns);

        $env = $this->option('env') ?: $this->argument('environment');
        if (!$env && !$this->option('domain')) {
            $env = 'local';
        }

        $vpnOnly = (bool) $this->option('vpn-only');

        $manifest = view('k8s.monitoring.shared', [
            'host' => $host,
            'grafanaPassword' => $password,
            'isLocal' => $env === 'local',
            'vpnOnly' => $vpnOnly,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-monitoring.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying monitoring manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Prometheus...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/prometheus -n {$ns} --timeout=120s",
            130,
        ));
        $this->withSpin('Waiting for Loki...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/loki -n {$ns} --timeout=120s",
            130,
        ));
        $this->withSpin('Waiting for kube-state-metrics...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/kube-state-metrics -n {$ns} --timeout=120s",
            130,
        ));
        $this->withSpin('Waiting for Grafana...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/grafana -n {$ns} --timeout=120s",
            130,
        ));
        $this->withSpin('Waiting for Promtail...', fn () => $this->runStreaming(
            "{$kubectl} rollout status daemonset/promtail -n {$ns} --timeout=120s",
            130,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Monitoring stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Grafana:</>            <fg=blue>https://{$host}</>  <fg=gray>admin / {$password}</>");
        $this->line("  <fg=gray>Prometheus:</>         prometheus.{$ns}.svc.cluster.local:9090  <fg=gray>(in-cluster)</>");
        $this->line("  <fg=gray>Loki:</>               loki.{$ns}.svc.cluster.local:3100  <fg=gray>(in-cluster)</>");
        $this->newLine();
        $this->line('  Prometheus + Loki are pre-wired as Grafana data sources.');
        $this->line('  Run <fg=yellow>larakube up</> to wire per-service exporters (MySQL, Redis, etc.).');
        $this->newLine();

        return 0;
    }

    protected function removeMonitoring(): int
    {
        $kubectl = $this->monitoringKubectl($this->option('context'));
        $ns = $this->monitoringNamespace();

        $this->withSpin('Removing Prometheus...', fn () => Process::run(
            "{$kubectl} delete deployment,svc,configmap,pvc,serviceaccount"
            .' prometheus prometheus-config prometheus-storage'
            ." -n {$ns} --ignore-not-found",
        ));

        $this->withSpin('Removing Loki...', fn () => Process::run(
            "{$kubectl} delete deployment,svc,configmap,pvc"
            .' loki loki-config loki-storage'
            ." -n {$ns} --ignore-not-found",
        ));

        $this->withSpin('Removing Promtail...', fn () => Process::run(
            "{$kubectl} delete daemonset,configmap,serviceaccount"
            .' promtail promtail-config'
            ." -n {$ns} --ignore-not-found",
        ));

        $this->withSpin('Removing kube-state-metrics...', fn () => Process::run(
            "{$kubectl} delete deployment,svc,serviceaccount"
            .' kube-state-metrics'
            ." -n {$ns} --ignore-not-found",
        ));

        $this->withSpin('Removing Grafana...', fn () => Process::run(
            "{$kubectl} delete deployment,svc,ingress,secret,configmap"
            .' grafana grafana-admin grafana-datasources'
            ." -n {$ns} --ignore-not-found",
        ));

        $this->withSpin('Removing cluster RBAC...', fn () => Process::run(
            "{$kubectl} delete clusterrole,clusterrolebinding"
            .' larakube-prometheus larakube-promtail larakube-kube-state-metrics'
            .' --ignore-not-found',
        ));

        $this->laraKubeInfo('Monitoring stack removed from larakube-shared.');

        return 0;
    }

    /**
     * Resolve the Grafana ingress host for this install.
     *
     *   1. --domain raw override → grafana.{domain}, no prompt, no persist.
     *   2. Local env → grafana.{global dev TLD}. The dev-TLD convention owns the
     *      local host; never prompted.
     *   3. Non-local env → the install-time prompt. Monitoring is opt-in PER env
     *      (you might skip staging but want production), so running monitor:init
     *      against a cloud env is the moment we know the user wants it here — and
     *      the moment to ask for its host, mirroring cloud:deploy's web-domain
     *      guard. Already-configured hosts in .larakube.json are reused (no
     *      re-prompt); a fresh answer is persisted back to the same hosts map.
     */
    protected function resolveGrafanaHost(): string
    {
        $service = SharedClusterService::GRAFANA;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        $env = $this->resolveEnvironment();

        if ($env === 'local') {
            return (string) $this->resolveGrafanaHostReadOnly('local', null);
        }

        return $this->promptForCloudGrafanaHost($service, $env);
    }

    /**
     * Decide which environment this install targets, mirroring plex:init's
     * interactive selection. An explicit positional argument (or the legacy
     * --env option) wins; otherwise prompt with the project's environments
     * (local + any cloud envs). Non-interactive — or a raw --domain override —
     * falls back to local.
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
            label: 'Which environment is this monitoring install for?',
            options: array_combine($envs, $envs),
            default: 'local',
            hint: 'Local uses your dev TLD; a cloud env asks for + persists the Grafana host.',
        );
    }

    /**
     * Prompt for (and persist) a non-local Grafana host, reusing any host already
     * configured for the env. Falls back to an ephemeral prompt when there's no
     * project .larakube.json to read from or write to.
     */
    protected function promptForCloudGrafanaHost(SharedClusterService $service, string $env): string
    {
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        // Already configured for this env? Use it untouched — no re-prompt.
        $existing = $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
        if ($existing) {
            return $existing;
        }

        // Default to the derived host (grafana-{webHost}) only when a web host is
        // configured; otherwise leave it blank so the user must type a real host.
        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        $default = ($config && $webHost) ? $config->getSharedServiceHost($service, $env) : '';

        $host = text(
            label: "What host should {$service->label()} use in '{$env}'?",
            placeholder: $default !== '' ? $default : 'e.g. grafana.example.com',
            default: $default,
            required: true,
            hint: 'Point this DNS at the cluster and add TLS like any other ingress host.',
        );

        // Persist so re-runs don't re-prompt and other tooling sees it.
        if ($config) {
            $config->setHost($env, $service->value, $host);
            $config->saveToFile($projectPath);
            $this->laraKubeInfo("Saved {$service->label()} host for '{$env}' to .larakube.json");
        }

        return $host;
    }

    /**
     * Return the existing Grafana admin password (stable across re-runs)
     * or generate a fresh one for first install.
     */
    protected function resolveGrafanaPassword(string $kubectl, string $ns): string
    {
        return $this->readGrafanaPassword($kubectl, $ns) ?? bin2hex(random_bytes(12));
    }
}
