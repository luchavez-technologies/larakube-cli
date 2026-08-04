<?php

namespace App\Commands\Support;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSupport;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolBranding;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class SupportInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithSupport, LaraKubeOutput, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'support:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Support (example.com → prefix.example.com)}
        {--app-name= : Custom branding name for Chatwoot (defaults to Support)}
        {--logo-url= : Custom logo URL for Chatwoot}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Chatwoot helpdesk stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySupport();
    }

    protected function deploySupport(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->supportKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::SUPPORT, ClusterTool::SUPPORT, $env, $kubectl);

        $ns = $this->supportNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::SUPPORT, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Chatwoot requires Postgres and Redis.
        if (! $this->ensureCommons(['postgres', 'redis'])) {
            return 1;
        }

        $dbPassword = $this->readSupportSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $secretKeyBase = $this->readSupportSecret($kubectl, $ns, 'secret-key-base') ?? bin2hex(random_bytes(32));

        $dbName = 'support_chatwoot';

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $redisIndex = $this->allocateCommonsRedisIndex('support_chatwoot');

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $secretKeyBase) {
            $cmd = "{$kubectl} create secret generic support-chatwoot-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=secret-key-base='.escapeshellarg($secretKeyBase).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);
        });

        $branding = $this->resolveToolBranding($kubectl, ClusterTool::SUPPORT);

        $manifest = view('k8s.support.shared', [
            'host' => $host,
            'appName' => $branding['appName'],
            'logoUrl' => $branding['logoUrl'],
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'redisIndex' => $redisIndex,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-support-chatwoot.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Chatwoot manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Chatwoot...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/support-chatwoot -n {$ns} --timeout=180s",
            190,
        ));

        $this->registerDeployedTool(ClusterTool::SUPPORT, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Chatwoot support stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Database:</>    <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->line("  <fg=gray>Redis DB:</>    <fg=blue>{$redisIndex}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::SUPPORT);
    }
}
