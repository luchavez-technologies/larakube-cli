<?php

namespace App\Commands\Link;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithLink;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolBranding;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class LinkInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithLink, InteractsWithPlex, LaraKubeOutput, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

    protected $signature = 'link:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Link (example.com → prefix.example.com)}
        {--app-name= : Custom branding name for Kutt (defaults to Links)}
        {--logo-url= : Custom logo URL for Kutt}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG_DEFAULT_ON;

    protected $description = 'Deploy the Kutt link shortener stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployLink();
    }

    protected function deployLink(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->linkKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::LINK, ClusterTool::LINK, $env, $kubectl);

        $ns = $this->linkNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->assertVpnOnlySupported(ClusterTool::LINK)) {
            return 1;
        }

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::LINK, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Kutt requires Postgres and Redis.
        if (! $this->ensureCommons(['postgres', 'redis'])) {
            return 1;
        }

        $dbPassword = $this->readLinkSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $jwtSecret = $this->readLinkSecret($kubectl, $ns, 'jwt-secret') ?? bin2hex(random_bytes(32));

        $dbName = 'link_kutt';

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $redisIndex = $this->allocateCommonsRedisIndex('link_kutt');

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $jwtSecret): void {
            $cmd = "{$kubectl} create secret generic link-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=jwt-secret='.escapeshellarg($jwtSecret).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);
        });

        $branding = $this->resolveToolBranding($kubectl, ClusterTool::LINK);
        $instance = ClusterTool::LINK->instanceSlugFromHost($host);
        $deploymentName = ClusterTool::LINK->primaryComponent($instance)->deployment;

        $manifest = view('k8s.link.shared', [
            'instance' => $instance,
            'host' => $host,
            'appName' => $branding['appName'],
            'logoUrl' => $branding['logoUrl'],
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'redisIndex' => $redisIndex,
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-link-kutt.yaml');
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Kutt manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $deploymentName, 180),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::LINK, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Kutt shortener stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Database:</>    <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->line("  <fg=gray>Redis DB:</>    <fg=blue>{$redisIndex}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::LINK);
    }
}
