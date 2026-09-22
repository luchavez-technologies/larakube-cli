<?php

namespace App\Commands\Analytics;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithAnalytics;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class AnalyticsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithAnalytics, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, RefusesUnshippedTools, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

    protected $signature = 'analytics:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Umami (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Umami web analytics stack into larakube-shared';

    public function handle(): int
    {
        if ($this->refuseUnshippedTool(ClusterTool::ANALYTICS)) {
            return 1;
        }

        $this->renderHeader();

        return $this->deployAnalytics();
    }

    protected function deployAnalytics(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $cluster = Kubectl::forContext(($context ?? '') !== '' ? $context : null);
        $kubectl = $cluster->prefix();
        $host = $this->resolveToolHost(SharedClusterService::ANALYTICS, ClusterTool::ANALYTICS, $env, $kubectl);
        $names = ToolInstance::forHost(ClusterTool::ANALYTICS, $host);
        $ns = $names->namespace();
        $instance = $names->instance;
        $dbName = $names->database();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::ANALYTICS, $kubectl, $instance)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Umami requires Postgres from Plex Commons.
        if (! $this->ensureCommons(['postgres'])) {
            return 1;
        }

        // Stable secrets across re-runs.
        $dbPassword = $this->readAnalyticsSecret($kubectl, $ns, 'db-password', $names) ?? Str::random(24);
        $appSecret = $this->readAnalyticsSecret($kubectl, $ns, 'app-secret', $names) ?? bin2hex(random_bytes(32));

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', fn () => $cluster->putSecret($ns, $names->secret(), [
            'db-password' => $dbPassword,
            'app-secret' => $appSecret,
        ], $names->labels()));

        $manifest = view('k8s.analytics.shared', [
            'names' => $names,
            'host' => $host,
            'dbName' => $dbName,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-analytics.yaml');
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Umami analytics manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $names->deployment(), 300),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::ANALYTICS, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Umami analytics stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Database:</>    <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::ANALYTICS);
    }
}
