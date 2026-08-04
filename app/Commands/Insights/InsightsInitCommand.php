<?php

namespace App\Commands\Insights;

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithInsights;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class InsightsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithInsights, InteractsWithPlex, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'insights:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Insights (example.com → prefix.example.com)}
        {--no-plex   : Bypass Plex Commons and deploy a dedicated database}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Metabase BI stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployInsights();
    }

    protected function deployInsights(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->insightsKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::INSIGHTS, ClusterTool::INSIGHTS, $env, $kubectl);

        $ns = $this->insightsNamespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::INSIGHTS, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres'])) {
                return 1;
            }
        }

        $dbPassword = $this->readInsightsDbPassword($kubectl, $ns) ?? Str::random(24);
        $encryptionKey = $this->readInsightsEncryptionKey($kubectl, $ns) ?? Str::random(64);

        if (! $noPlex) {
            if (! $this->allocateDatabase(\App\Enums\DatabaseDriver::POSTGRESQL, 'metabase', $dbPassword)) {
                return 1;
            }
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $encryptionKey) {
            Process::run(
                "{$kubectl} create secret generic insights-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=encryption-key='.escapeshellarg($encryptionKey).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $manifest = view('k8s.insights.shared', [
            'host' => $host,
            'dbPassword' => $dbPassword,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-insights.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Insights (Metabase) manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Insights (Metabase)...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/insights-metabase -n {$ns} --timeout=120s",
            130,
        ));

        $this->registerDeployedTool(ClusterTool::INSIGHTS, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Insights (Metabase) stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::INSIGHTS);
    }

    protected function readInsightsEncryptionKey(string $kubectl, string $ns): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'insights-secrets', 'encryption-key');
    }
}
