<?php

namespace App\Commands\Monitor;

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithMonitoring;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolBranding;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\multiselect;

use LaravelZero\Framework\Commands\Command;

class MonitorInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithMonitoring, LaraKubeOutput, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'monitor:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted, like plex:init. A non-local env prompts for + persists the Grafana host.}
        {--context=   : Target a specific kube-context (defaults to current context)}
        {--domain=    : Base domain OR full host for Grafana (example.com → grafana.example.com; grafana.example.com used as-is)}
        {--app-name=  : Custom branding name for Grafana (defaults to Monitor)}
        {--logo-url=  : Custom logo / favicon URL for Grafana}
        {--vpn-only   : Restrict access via NetBird VPN IP whitelisting}
        {--no-logs    : Skip deploying Loki + Promtail log aggregation (~300MB RAM saved)}
        {--with-logs  : Force deploying Loki + Promtail log aggregation}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the cluster-wide monitoring stack (Prometheus, Loki, Grafana) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployMonitoring();
    }

    protected function deployMonitoring(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->monitoringKubectl($context);
        $ns = $this->monitoringNamespace();

        $host = $this->resolveToolHost(SharedClusterService::GRAFANA, ClusterTool::MONITOR, $env, $kubectl);

        $withLogs = $this->resolveLogAggregation();

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $password = $this->resolveGrafanaPassword($kubectl, $ns);

        $vpnOnly = (bool) $this->option('vpn-only');
        $branding = $this->resolveToolBranding($kubectl, ClusterTool::MONITOR);

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::MONITOR, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $manifest = view('k8s.monitoring.shared', [
            'host' => $host,
            'appName' => $branding['appName'],
            'logoUrl' => $branding['logoUrl'],
            'grafanaPassword' => $password,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'vpnOnly' => $vpnOnly,
            'withLogs' => $withLogs,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-monitoring.yaml';
        file_put_contents($tmp, $manifest);

        // Multiple resources to verify per apply (prometheus/loki/ksm/grafana/
        // promtail), so this can't use the single apply+rollout
        // applyAndVerifyRollout() helper — every step below checks its real
        // exit code via an explicit ->timeout() exceeding its own kubectl
        // --timeout flag, or a rejected apply / stuck rollout prints ✔ and
        // this command claims success regardless (confirmed live on
        // Documenso, 2026-08-05 — same root cause as the missing-timeout
        // ProcessTimedOutException crash found the same day).
        $applied = $this->withSpin('Applying monitoring manifests...', fn () => Process::timeout(70)->run("{$kubectl} apply -f {$tmp} --request-timeout=60s")->successful());
        @unlink($tmp);

        if (! $applied) {
            $this->laraKubeError('Could not apply the monitoring manifest — see the output above.');

            return 1;
        }

        if (! $this->withSpin('Waiting for Prometheus...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/prometheus -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('prometheus never became Ready.');

            return 1;
        }
        if ($withLogs && ! $this->withSpin('Waiting for Loki...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/loki -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('loki never became Ready.');

            return 1;
        }
        if (! $this->withSpin('Waiting for kube-state-metrics...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/kube-state-metrics -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('kube-state-metrics never became Ready.');

            return 1;
        }
        if (! $this->withSpin('Waiting for Grafana...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/grafana -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('grafana never became Ready.');

            return 1;
        }
        if ($withLogs && ! $this->withSpin('Waiting for Promtail...', fn () => Process::timeout(130)->run("{$kubectl} rollout status daemonset/promtail -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('promtail never became Ready.');

            return 1;
        }

        $this->registerDeployedTool(ClusterTool::MONITOR, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Monitoring stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Grafana:</>            <fg=blue>https://{$host}</>  <fg=gray>admin / {$password}</>");
        $this->line("  <fg=gray>Prometheus:</>         prometheus.{$ns}.svc.cluster.local:9090  <fg=gray>(in-cluster)</>");
        if ($withLogs) {
            $this->line("  <fg=gray>Loki:</>               loki.{$ns}.svc.cluster.local:3100  <fg=gray>(in-cluster)</>");
        }
        $this->newLine();
        if ($withLogs) {
            $this->line('  Prometheus + Loki are pre-wired as Grafana data sources.');
        } else {
            $this->line('  Prometheus is pre-wired as Grafana data source.');
            $this->line('  <fg=yellow>Note:</> Log aggregation (Loki + Promtail) is disabled (~300MB RAM saved).');
            $this->line('  Run <fg=yellow>larakube monitor:init --with-logs</> anytime to enable log search in Grafana.');
        }
        if ($env === 'local') {
            $this->line('  Run <fg=yellow>larakube up</> to wire local per-service exporters (MySQL, Redis, etc.).');
        } else {
            $this->line('  Services with <fg=gray>prometheus.io/scrape=true</> annotations are automatically scraped.');
        }
        $this->newLine();

        return 0;
    }

    /**
     * Determine whether to deploy log aggregation (Loki + Promtail).
     *
     * In non-interactive environments, default to metrics-only (--no-logs) to save RAM.
     * In interactive human terminals, prompt with a multiselect.
     */
    protected function resolveLogAggregation(): bool
    {
        if ($this->option('no-logs')) {
            return false;
        }

        if ($this->option('with-logs')) {
            return true;
        }

        if ($this->option('no-interaction') || ! stream_isatty(STDIN)) {
            return false;
        }

        $selected = multiselect(
            label: 'Which monitoring components would you like to deploy?',
            options: [
                'metrics' => 'Metrics & Alerting (Grafana, Prometheus, kube-state-metrics) — ~150MB RAM',
                'logs' => 'Log Aggregation (Loki, Promtail) — ~300MB RAM',
            ],
            default: ['metrics', 'logs'],
            required: true,
        );

        return in_array('logs', $selected, true);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::MONITOR);
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
