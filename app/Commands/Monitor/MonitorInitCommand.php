<?php

namespace App\Commands\Monitor;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithMonitoring;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolBranding;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

use LaravelZero\Framework\Commands\Command;

class MonitorInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithMonitoring, InteractsWithPlex, LaraKubeOutput, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets;

    protected $signature = 'monitor:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted, like plex:init. A non-local env prompts for + persists the Grafana host.}
        {--context=   : Target a specific kube-context (defaults to current context)}
        {--domain=    : Base domain OR full host for Grafana (example.com → grafana.example.com; grafana.example.com used as-is)}
        {--app-name=  : Custom branding name for Grafana (defaults to Monitor)}
        {--logo-url=  : Custom logo / favicon URL for Grafana}
        {--vpn-only   : Restrict access via NetBird VPN IP whitelisting}
        {--no-logs    : Skip deploying Loki + Promtail log aggregation (~300MB RAM saved)}
        {--with-logs  : Force deploying Loki + Promtail log aggregation}
        {--no-traces  : Skip deploying Tempo trace storage (~450MB RAM saved)}
        {--with-traces : Force deploying Tempo trace storage}
        {--no-plex   : Bypass Plex Commons — Grafana keeps its own database on a local PVC instead of Commons Postgres}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the cluster-wide monitoring stack (Grafana, Prometheus, Loki, Tempo) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployMonitoring();
    }

    protected function deployMonitoring(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->monitoringKubectl($context);
        $ns = $this->monitoringNamespace();

        $host = $this->resolveToolHost(SharedClusterService::GRAFANA, ClusterTool::MONITOR, $env, $kubectl);

        [$withLogs, $withTraces] = $this->resolveMonitoringComponents();

        [
            'withLogs' => $withLogs,
            'withTraces' => $withTraces,
            'removedLogs' => $removedLogs,
            'removedTraces' => $removedTraces,
            'datasourcesChanged' => $datasourcesChanged,
        ] = $this->reconcileMonitoringComponents($kubectl, $ns, $withLogs, $withTraces);

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $password = $this->resolveGrafanaPassword($kubectl, $ns);

        // Grafana's own database (dashboards created/edited via the UI, not
        // the dashboards-as-code provisioned into the 'LaraKube' folder) was
        // previously unpersisted — no PVC, no external DB, just Grafana's
        // built-in SQLite on the pod's ephemeral filesystem, wiped on every
        // pod recreation. Confirmed live 2026-08-18 — a teammate's dashboard
        // work was lost this way. Default: a real Commons Postgres tenant,
        // same pattern every other Commons-backed tool already uses — it also
        // rides along with the existing nightly Commons backup for free.
        // --no-plex is the fallback for a cluster with no Plex Commons at
        // all: still-persistent (a PVC survives pod recreation, unlike the
        // old ephemeral-only setup) but plain SQLite, uninvolved in any
        // backup routine — mirrors git:init's own --no-plex story.
        $noPlex = (bool) $this->option('no-plex');
        $dbPassword = null;

        if (! $noPlex) {
            $dbPassword = $this->readGrafanaDbPassword($kubectl, $ns) ?? Str::random(24);

            if (! $this->ensureCommons(['postgres'])) {
                return 1;
            }

            // Once OpenBao's database secrets engine already owns the
            // 'grafana' static role (because `secrets:wire --tool=monitor`
            // was run at some point), defer to ITS current password instead
            // of re-affirming a locally-cached one that may predate OpenBao's
            // own rotation — see resolveManagedDbPassword()'s docblock (the
            // same gap took Forgejo down 2026-08-15). This is a READ, not a
            // write: it never registers anything with OpenBao itself — only
            // `secrets:wire` does that. `monitor:init` doesn't know or care
            // whether OpenBao exists otherwise; see ADR-adjacent note in
            // GitInitCommand — `{tool}:init` must never call
            // registerStaticRole()/isOpenBaoBootstrapped() to INITIATE
            // rotation, only to avoid clobbering it if already active.
            $dbPassword = $this->resolveManagedDbPassword($kubectl, 'grafana', $dbPassword);

            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, 'grafana', $dbPassword)) {
                return 1;
            }
        }

        $vpnOnly = (bool) $this->option('vpn-only');
        $branding = $this->resolveToolBranding($kubectl, ClusterTool::MONITOR);

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::MONITOR, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $this->syncClusterDashboardConfigMaps($kubectl, $ns, $withLogs, $withTraces)) {
            $this->laraKubeError('Could not sync the Grafana dashboards — see the output above.');

            return 1;
        }

        $manifest = view('k8s.monitoring.shared', [
            'host' => $host,
            'appName' => $branding['appName'],
            'logoUrl' => $branding['logoUrl'],
            'grafanaPassword' => $password,
            'noPlex' => $noPlex,
            'dbPassword' => $dbPassword,
            'plexNamespace' => $noPlex ? null : $this->plexNamespace(),
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'vpnOnly' => $vpnOnly,
            'withLogs' => $withLogs,
            'withTraces' => $withTraces,
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

        if ($removedLogs) {
            if (! $this->removeResources('Removing Loki...', "{$kubectl} delete deployment,svc,configmap,pvc loki loki-config loki-storage -n {$ns} --ignore-not-found")
                || ! $this->removeResources('Removing Promtail...', "{$kubectl} delete daemonset,configmap,serviceaccount promtail promtail-config -n {$ns} --ignore-not-found")) {
                $this->laraKubeError('Could not remove the previously-deployed log aggregation stack — see the output above.');

                return 1;
            }
        }

        if ($removedTraces) {
            if (! $this->removeResources('Removing Tempo...', "{$kubectl} delete deployment,svc,configmap,pvc tempo tempo-config tempo-storage -n {$ns} --ignore-not-found")) {
                $this->laraKubeError('Could not remove the previously-deployed trace storage — see the output above.');

                return 1;
            }
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
        if ($withTraces && ! $this->withSpin('Waiting for Tempo...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/tempo -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('tempo never became Ready.');

            return 1;
        }

        if ($datasourcesChanged) {
            if (! $this->withSpin('Restarting Grafana to load the updated data sources...', fn () => Process::timeout(70)->run("{$kubectl} rollout restart deploy/grafana -n {$ns}")->successful())) {
                $this->laraKubeError('Could not restart Grafana — see the output above.');

                return 1;
            }

            if (! $this->withSpin('Waiting for Grafana after restart...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/grafana -n {$ns} --timeout=120s")->successful())) {
                $this->laraKubeError('grafana never became Ready after restart.');

                return 1;
            }
        }

        $this->registerDeployedTool(ClusterTool::MONITOR, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Monitoring stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Grafana:</>            <fg=blue>https://{$host}</>  <fg=gray>admin / {$password}</>");
        $this->line($noPlex
            ? '  <fg=gray>Grafana database:</>  SQLite on a local PVC (not backed up — run with the default Commons Postgres for that).'
            : '  <fg=gray>Grafana database:</>  Commons Postgres (persists dashboards/users across restarts, covered by the nightly Commons backup).');
        $this->line("  <fg=gray>Prometheus:</>         prometheus.{$ns}.svc.cluster.local:9090  <fg=gray>(in-cluster)</>");
        if ($withLogs) {
            $this->line("  <fg=gray>Loki:</>               loki.{$ns}.svc.cluster.local:3100  <fg=gray>(in-cluster)</>");
        }
        if ($withTraces) {
            $this->line("  <fg=gray>Tempo:</>              tempo.{$ns}.svc.cluster.local:3200  <fg=gray>(in-cluster, OTLP :4317/:4318)</>");
        }
        $this->newLine();
        $this->line('  Prometheus'.($withLogs ? ' + Loki' : '').($withTraces ? ' + Tempo' : '').' are pre-wired as Grafana data sources.');

        $dashboards = ['Cluster Overview', 'Nodes', 'Pods'];
        if ($withLogs) {
            $dashboards[] = 'Loki Logs';
        }
        if ($withTraces) {
            $dashboards[] = 'Tempo Service Graph';
        }
        $this->line('  Dashboards: '.implode(', ', $dashboards).'.');
        $this->newLine();
        if ($removedLogs) {
            $this->line('  <fg=yellow>Log aggregation (Loki + Promtail) removed — run <fg=cyan>larakube monitor:init --with-logs</> anytime to re-enable.</>');
        } elseif (! $withLogs) {
            $this->line('  <fg=yellow>Note:</> Log aggregation (Loki + Promtail) is disabled (~300MB RAM saved).');
            $this->line('  Run <fg=yellow>larakube monitor:init --with-logs</> anytime to enable log search in Grafana.');
        }
        if ($removedTraces) {
            $this->line('  <fg=yellow>Tempo removed — run <fg=cyan>larakube monitor:init --with-traces</> anytime to re-enable.</>');
        } elseif (! $withTraces) {
            $this->line('  <fg=yellow>Note:</> Distributed tracing (Tempo) is disabled (~450MB RAM saved).');
            $this->line('  Run <fg=yellow>larakube monitor:init --with-traces</> anytime to enable trace search in Grafana.');
        }
        $this->newLine();
        if ($env === 'local') {
            $this->line('  Run <fg=yellow>larakube up</> to wire local per-service exporters (MySQL, Redis, etc.).');
        } else {
            $this->line('  Services with <fg=gray>prometheus.io/scrape=true</> annotations are automatically scraped.');
        }
        $this->newLine();

        return 0;
    }

    /**
     * Determine which optional monitoring components to deploy.
     *
     * Flag pairs win outright: --with-logs/--no-logs and
     * --with-traces/--no-traces. In non-interactive environments the default
     * is a metrics-only stack to save RAM; interactive terminals get one
     * multiselect covering both optional components.
     *
     * @return array{0: bool, 1: bool} [withLogs, withTraces]
     */
    protected function resolveMonitoringComponents(): array
    {
        $logsExplicit = $this->option('with-logs') || $this->option('no-logs');
        $tracesExplicit = $this->option('with-traces') || $this->option('no-traces');

        if ($logsExplicit || $tracesExplicit) {
            return [
                $logsExplicit ? (bool) $this->option('with-logs') : false,
                $tracesExplicit ? (bool) $this->option('with-traces') : false,
            ];
        }

        if ($this->option('no-interaction') || ! stream_isatty(STDIN)) {
            return [false, false];
        }

        $selected = multiselect(
            label: 'Which monitoring components would you like to deploy?',
            options: [
                'metrics' => 'Metrics & Alerting (Grafana, Prometheus, kube-state-metrics) — ~150MB RAM',
                'logs' => 'Log Aggregation (Loki, Promtail) — ~300MB RAM',
                'traces' => 'Distributed Tracing (Tempo + metrics-generator) — ~450MB RAM',
            ],
            default: ['metrics', 'logs'],
            required: true,
        );

        return [in_array('logs', $selected, true), in_array('traces', $selected, true)];
    }

    /**
     * Reconcile the desired component set against what is actually deployed.
     *
     * A requested component that is absent is simply a new install. A
     * component that is deployed but no longer requested is reversed in
     * place (the confirm happens before any manifest change, so declining
     * leaves the cluster untouched and keeps the component enabled).
     * Datasource changes only matter when Grafana already exists — it loads
     * provisioning at startup, so a rollout restart is needed to pick up a
     * configmap that changed under a running pod.
     *
     * @return array{withLogs: bool, withTraces: bool, removedLogs: bool, removedTraces: bool, datasourcesChanged: bool}
     */
    protected function reconcileMonitoringComponents(string $kubectl, string $ns, bool $withLogs, bool $withTraces): array
    {
        $grafanaPresent = Process::run("{$kubectl} get deployment/grafana -n {$ns} --no-headers")->successful();

        $lokiPresent = Process::run("{$kubectl} get deployment/loki -n {$ns} --no-headers")->successful();
        $tempoPresent = Process::run("{$kubectl} get deployment/tempo -n {$ns} --no-headers")->successful();

        $lokiMismatch = $withLogs !== $lokiPresent;
        $tempoMismatch = $withTraces !== $tempoPresent;

        $removedLogs = false;
        $removedTraces = false;

        if (! $withLogs && $lokiMismatch) {
            if ($this->confirmComponentRemoval('Log aggregation (Loki + Promtail) is currently deployed.', 'This will delete Loki + Promtail and wipe loki-storage (~10Gi of historical logs).')) {
                $removedLogs = true;
            } else {
                $withLogs = true;
                $lokiMismatch = false;
            }
        }

        if (! $withTraces && $tempoMismatch) {
            if ($this->confirmComponentRemoval('Tempo trace storage is currently deployed.', 'This will delete Tempo and wipe tempo-storage (~5Gi of trace data).')) {
                $removedTraces = true;
            } else {
                $withTraces = true;
                $tempoMismatch = false;
            }
        }

        return [
            'withLogs' => $withLogs,
            'withTraces' => $withTraces,
            'removedLogs' => $removedLogs,
            'removedTraces' => $removedTraces,
            'datasourcesChanged' => $grafanaPresent && ($lokiMismatch || $tempoMismatch),
        ];
    }

    /**
     * Gate a destructive component removal behind a Laravel Prompts confirm().
     *
     * Skipped in non-interactive environments (an explicit --no-logs /
     * --no-traces flag is already a deliberate choice) and under --force,
     * matching ConfirmsDestructiveAction's contract for automation.
     */
    protected function confirmComponentRemoval(string $title, string $detail): bool
    {
        if ($this->option('force') || $this->option('no-interaction') || ! stream_isatty(STDIN)) {
            return true;
        }

        return confirm(
            label: "{$title} {$detail}",
            default: false,
        );
    }

    /**
     * Recreate the grafana-dashboards ConfigMap with exactly the JSON files
     * for the current component set. kubectl apply preserves the existing
     * ConfigMap, and the dashboard provider rescans its path every 10s, so
     * toggling components adds/removes dashboards without a Grafana restart.
     */
    protected function syncClusterDashboardConfigMaps(string $kubectl, string $ns, bool $withLogs, bool $withTraces): bool
    {
        $dir = resource_path('dashboards');

        $files = ['cluster-overview.json', 'nodes.json', 'pods.json'];

        if ($withLogs) {
            $files[] = 'loki-logs.json';
        }

        if ($withTraces) {
            $files[] = 'tempo-service-graph.json';
        }

        // resource_path() resolves inside the phar when running from the
        // compiled binary — kubectl is a separate process and can't read
        // phar:// paths, so each file is copied out to a real tmp path first.
        $tmpFiles = [];
        foreach ($files as $file) {
            $tmp = tempnam(sys_get_temp_dir(), 'lk_dash_');
            copy("{$dir}/{$file}", $tmp);
            $tmpFiles[$file] = $tmp;
        }

        $fromFiles = implode(' ', array_map(
            fn (string $file, string $tmp) => '--from-file='.escapeshellarg("{$file}={$tmp}"),
            array_keys($tmpFiles),
            $tmpFiles,
        ));

        $result = $this->withSpin(
            'Syncing Grafana dashboards...',
            fn () => Process::timeout(70)->run("{$kubectl} create configmap grafana-dashboards {$fromFiles} -n {$ns} --dry-run=client -o yaml | {$kubectl} apply -f - --request-timeout=60s")->successful(),
        );

        foreach ($tmpFiles as $tmp) {
            @unlink($tmp);
        }

        return $result;
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
