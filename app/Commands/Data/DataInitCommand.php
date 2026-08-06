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

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class DataInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithData, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithSecrets, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected $signature = 'data:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--engine=   : Target data engine — "pocketbase" (default) or "directus"}
        {--instance=main : Named instance identifier for multi-instance deployments}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Data (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG_DEFAULT_ON;

    protected $description = 'Deploy a Data / Headless CMS stack (PocketBase or Directus) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployData();
    }

    protected function deployData(): int
    {
        $engine = $this->resolveEngine();
        $instance = (string) ($this->option('instance') ?: 'main');
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

        $s3Creds = $this->readCommonsS3Credentials();
        $s3Key = $s3Creds['access'] ?? 'seaweedfs-access-key';
        $s3Secret = $s3Creds['secret'] ?? 'seaweedfs-secret-key';

        $secretName = $instance !== 'main' ? "data-secrets-{$instance}" : 'data-secrets';
        $deployName = ClusterTool::DATA->deploymentName($instance, $engine);

        $secret = $this->readDataSecret($kubectl, $ns, 'secret') ?? Str::uuid()->toString();
        $key = $this->readDataSecret($kubectl, $ns, 'key') ?? Str::uuid()->toString();
        $dbPassword = $this->readDataSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $adminPassword = $this->readDataSecret($kubectl, $ns, 'admin-password') ?? Str::random(24);

        $parts = explode('.', $host);
        $domain = count($parts) > 2 ? implode('.', array_slice($parts, 1)) : $host;
        $adminEmail = $this->readDataSecret($kubectl, $ns, 'admin-email') ?? "admin@{$domain}";

        $bucket = ClusterTool::DATA->commonsBuckets($instance)[0] ?? 'data-storage';

        if ($engine === 'directus') {
            if (! $this->ensureCommons(['postgres', 'redis'])) {
                return 1;
            }

            $dbName = ClusterTool::DATA->commonsDatabases($instance, $engine)[0] ?? 'data_directus';
            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
                return 1;
            }

            $redisIndex = $this->allocateCommonsRedisIndex($dbName);
        } else {
            // PocketBase uses embedded SQLite, so no Postgres or Redis required
            $dbName = 'SQLite (embedded)';
            $redisIndex = null;
        }

        $pvcName = $instance !== 'main' ? "data-pocketbase-pvc-{$instance}" : 'data-pocketbase-pvc';

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        if ($engine === 'pocketbase') {
            $this->withSpin("Ensuring PVC {$pvcName}...", function () use ($kubectl, $ns, $pvcName) {
                $cmd = "{$kubectl} apply -f - <<EOF\n"
                    ."apiVersion: v1\n"
                    ."kind: PersistentVolumeClaim\n"
                    ."metadata:\n"
                    ."  name: {$pvcName}\n"
                    ."  namespace: {$ns}\n"
                    ."spec:\n"
                    ."  accessModes:\n"
                    ."    - ReadWriteOnce\n"
                    ."  resources:\n"
                    ."    requests:\n"
                    ."      storage: 2Gi\n"
                    .'EOF';
                Process::run($cmd);
            });
        }

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $secretName, $secret, $key, $dbPassword, $adminEmail, $adminPassword, $s3Key, $s3Secret) {
            $cmd = "{$kubectl} create secret generic {$secretName} -n {$ns} "
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
            $prefix = $engine === 'pocketbase' ? 'DATA_POCKETBASE' : 'DATA_DIRECTUS';
            if ($instance !== 'main') {
                $prefix .= '_'.strtoupper(str_replace('-', '_', $instance));
            }

            $this->pushClusterSecret($kubectl, "{$prefix}_SECRET", $secret, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_KEY", $key, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_ADMIN_EMAIL", $adminEmail, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_ADMIN_PASSWORD", $adminPassword, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_S3_KEY", $s3Key, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_S3_SECRET", $s3Secret, $env);

            if ($engine === 'directus') {
                $this->pushClusterSecret($kubectl, "{$prefix}_DB_PASSWORD", $dbPassword, $env);
            }
        }

        $ssoWired = $this->readClusterSecretKey($kubectl, $ns, $instance !== 'main' ? "data-oidc-{$instance}" : 'data-oidc', $engine === 'pocketbase' ? 'POCKETBASE_OIDC_CLIENT_ID' : 'AUTH_ZITADEL_CLIENT_ID') !== null;

        $manifest = view('k8s.data.shared', [
            'engine' => $engine,
            'instance' => $instance,
            'deployName' => $deployName,
            'secretName' => $secretName,
            'pvcName' => $pvcName,
            'host' => $host,
            'namespace' => $ns,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'redisIndex' => $redisIndex ?? 0,
            'authProviders' => $ssoWired ? 'local,zitadel' : 'local',
        ])->render();

        $tmp = sys_get_temp_dir()."/larakube-{$deployName}.yaml";
        file_put_contents($tmp, $manifest);

        $engineLabel = $engine === 'pocketbase' ? 'PocketBase' : 'Directus';

        $rolledOut = $this->withSpin(
            "Applying {$engineLabel} manifests...",
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $deployName, 180),
        );
        @unlink($tmp);

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::DATA, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ {$engineLabel} Data / Headless CMS stack is live.");
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>      <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Admin Email:</>     <fg=blue>{$adminEmail}</>");
        $this->line("  <fg=gray>Admin Password:</>  <fg=yellow>{$adminPassword}</>");
        if ($engine === 'directus') {
            $this->line("  <fg=gray>Database:</>        <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
            $this->line("  <fg=gray>Redis DB:</>        <fg=blue>{$redisIndex}</>");
        } else {
            $this->line("  <fg=gray>Database:</>        <fg=blue>Embedded SQLite</> · PVC <fg=blue>{$pvcName}</>");
        }
        $this->line("  <fg=gray>Storage:</>         <fg=blue>SeaweedFS S3</> · Bucket <fg=blue>{$bucket}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::DATA);
    }

    protected function resolveEngine(): string
    {
        $explicit = strtolower((string) $this->option('engine'));
        if (in_array($explicit, ['pocketbase', 'directus'], true)) {
            return $explicit;
        }

        if ($this->option('no-interaction') || $this->option('force')) {
            return 'pocketbase';
        }

        return select(
            label: 'Which Data / Headless CMS engine would you like to deploy?',
            options: [
                'pocketbase' => 'PocketBase — Ultra-lightweight, zero-paywall, self-contained SQLite backend (Recommended)',
                'directus' => 'Directus — Full Postgres + Redis + S3 Headless CMS stack',
            ],
            default: 'pocketbase',
        );
    }
}
