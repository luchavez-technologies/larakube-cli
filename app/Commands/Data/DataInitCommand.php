<?php

namespace App\Commands\Data;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithData;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class DataInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithData, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithSecrets, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected $signature = 'data:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Data (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG_DEFAULT_ON;

    protected $description = 'Deploy the Directus Headless CMS stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployData();
    }

    protected function deployData(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->dataKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::DATA, ClusterTool::DATA, $env, $kubectl);
        $ns = $this->dataNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::DATA, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Directus requires Postgres, Redis, and SeaweedFS (S3).
        if (! $this->ensureCommons(['postgres', 'redis'])) {
            return 1;
        }

        $s3Creds = $this->readCommonsS3Credentials();
        $s3Key = $s3Creds['access'] ?? 'seaweedfs-access-key';
        $s3Secret = $s3Creds['secret'] ?? 'seaweedfs-secret-key';

        $secret = $this->readDataSecret($kubectl, $ns, 'secret') ?? Str::uuid()->toString();
        $key = $this->readDataSecret($kubectl, $ns, 'key') ?? Str::uuid()->toString();
        $dbPassword = $this->readDataSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $adminPassword = $this->readDataSecret($kubectl, $ns, 'admin-password') ?? Str::random(24);

        $parts = explode('.', $host);
        $domain = count($parts) > 2 ? implode('.', array_slice($parts, 1)) : $host;
        $adminEmail = $this->readDataSecret($kubectl, $ns, 'admin-email') ?? "admin@{$domain}";

        $dbName = 'data_directus';

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $redisIndex = $this->allocateCommonsRedisIndex('data_directus');

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $secret, $key, $dbPassword, $adminEmail, $adminPassword, $s3Key, $s3Secret) {
            $cmd = "{$kubectl} create secret generic data-secrets -n {$ns} "
                .'--from-literal=secret='.escapeshellarg($secret).' '
                .'--from-literal=key='.escapeshellarg($key).' '
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=admin-email='.escapeshellarg($adminEmail).' '
                .'--from-literal=admin-password='.escapeshellarg($adminPassword).' '
                .'--from-literal=s3-key='.escapeshellarg($s3Key).' '
                .'--from-literal=s3-secret='.escapeshellarg($s3Secret).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);
        });

        // Store to OpenBao vault if available
        if ($this->secretsBackendAvailable($kubectl)) {
            $this->pushClusterSecret($kubectl, 'DATA_DIRECTUS_SECRET', $secret, $env);
            $this->pushClusterSecret($kubectl, 'DATA_DIRECTUS_KEY', $key, $env);
            $this->pushClusterSecret($kubectl, 'DATA_DIRECTUS_DB_PASSWORD', $dbPassword, $env);
            $this->pushClusterSecret($kubectl, 'DATA_DIRECTUS_ADMIN_EMAIL', $adminEmail, $env);
            $this->pushClusterSecret($kubectl, 'DATA_DIRECTUS_ADMIN_PASSWORD', $adminPassword, $env);
            $this->pushClusterSecret($kubectl, 'DATA_DIRECTUS_S3_KEY', $s3Key, $env);
            $this->pushClusterSecret($kubectl, 'DATA_DIRECTUS_S3_SECRET', $s3Secret, $env);
        }

        $manifest = view('k8s.data.shared', [
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'redisIndex' => $redisIndex,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-data-directus.yaml';
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Directus manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, 'data-directus', 180),
        );
        @unlink($tmp);

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::DATA, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Directus Headless CMS stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>      <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Admin Email:</>     <fg=blue>{$adminEmail}</>");
        $this->line("  <fg=gray>Admin Password:</>  <fg=yellow>{$adminPassword}</>");
        $this->line("  <fg=gray>Database:</>        <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->line("  <fg=gray>Redis DB:</>        <fg=blue>{$redisIndex}</>");
        $this->line('  <fg=gray>Storage:</>         <fg=blue>SeaweedFS S3</> · Bucket <fg=blue>data-storage</>');
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::DATA);
    }
}
