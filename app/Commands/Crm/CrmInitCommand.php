<?php

namespace App\Commands\Crm;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithCrm;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class CrmInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithCrm, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

    protected $signature = 'crm:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for CRM (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Twenty CRM stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployCrm();
    }

    protected function deployCrm(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->crmKubectl($context);

        $domainOption = trim((string) ($this->option('domain') ?? ''));
        $host = $domainOption !== ''
            ? $this->sanitizeDomainInput($domainOption)
            : $this->resolveToolHost(SharedClusterService::CRM, ClusterTool::CRM, $env, $kubectl, deferRegistration: true);
        $instance = ClusterTool::CRM->instanceSlugFromHost($host);

        $ns = $this->crmNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::CRM, $kubectl, $instance)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Twenty CRM requires Postgres, Redis, and S3 (attachments — without
        // it Twenty falls back to STORAGE_TYPE=local, i.e. the container's
        // own ephemeral disk, silently losing every upload on the next
        // restart/Recreate).
        if (! $this->ensureCommons(['postgres', 'redis'])) {
            return 1;
        }

        // The Commons S3 backend is whatever the operator chose at
        // `plex:init` — never assume SeaweedFS (see NotesInitCommand/
        // SignInitCommand for the same detection).
        $spec = $this->getCommonsSpec();
        $s3Service = null;
        if ($spec !== null) {
            $enabled = $this->enabledCommonsServices($spec);
            if (in_array('seaweedfs', $enabled, true)) {
                $s3Service = 'seaweedfs';
            } elseif (in_array('minio', $enabled, true)) {
                $s3Service = 'minio';
            }
        }
        $s3Service ??= 'seaweedfs';
        if (! $this->ensureCommons([$s3Service])) {
            return 1;
        }
        $s3Driver = StorageDriver::from($s3Service);

        $secretName = "crm-twenty-secrets-{$instance}";
        $deploymentName = ClusterTool::CRM->deploymentName($instance);
        $workerDeploymentName = "crm-twenty-worker-{$instance}";
        $serviceName = "crm-{$instance}";
        $ingressName = $serviceName;
        $oidcSecretName = "crm-twenty-oidc-{$instance}";

        $dbPassword = $this->readCrmSecret($kubectl, $ns, 'db-password', $instance) ?? Str::random(24);
        $accessTokenSecret = $this->readCrmSecret($kubectl, $ns, 'access-token-secret', $instance) ?? bin2hex(random_bytes(32));
        $loginTokenSecret = $this->readCrmSecret($kubectl, $ns, 'login-token-secret', $instance) ?? bin2hex(random_bytes(32));
        $refreshTokenSecret = $this->readCrmSecret($kubectl, $ns, 'refresh-token-secret', $instance) ?? bin2hex(random_bytes(32));
        $fileTokenSecret = $this->readCrmSecret($kubectl, $ns, 'file-token-secret', $instance) ?? bin2hex(random_bytes(32));
        $encryptionKey = $this->readCrmSecret($kubectl, $ns, 'encryption-key', $instance) ?? bin2hex(random_bytes(32));

        $dbName = 'crm_twenty_'.str_replace('-', '_', $instance);
        $dbUser = $dbName;

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $redisKey = "crm_twenty_{$instance}";
        $redisIndex = $this->allocateCommonsRedisIndex($redisKey);

        $s3Creds = $this->readCommonsS3Credentials();
        $s3Key = $s3Creds['access'] ?? 'seaweedfs-access-key';
        $s3Secret = $s3Creds['secret'] ?? 'seaweedfs-secret-key';
        $bucket = ClusterTool::CRM->commonsBuckets($instance)[0] ?? "crm-twenty-storage-{$instance}";

        if (! $this->allocateStorageBucket($s3Driver, $bucket)) {
            return 1;
        }

        $s3Endpoints = $this->resolveCommonsS3Endpoints($s3Driver, 'Twenty CRM');

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $secretName, $dbPassword, $accessTokenSecret, $loginTokenSecret, $refreshTokenSecret, $fileTokenSecret, $encryptionKey, $s3Key, $s3Secret) {
            $cmd = "{$kubectl} create secret generic {$secretName} -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=access-token-secret='.escapeshellarg($accessTokenSecret).' '
                .'--from-literal=login-token-secret='.escapeshellarg($loginTokenSecret).' '
                .'--from-literal=refresh-token-secret='.escapeshellarg($refreshTokenSecret).' '
                .'--from-literal=file-token-secret='.escapeshellarg($fileTokenSecret).' '
                .'--from-literal=encryption-key='.escapeshellarg($encryptionKey).' '
                .'--from-literal=s3-key='.escapeshellarg($s3Key).' '
                .'--from-literal=s3-secret='.escapeshellarg($s3Secret).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);
        });

        $manifest = view('k8s.crm.shared', [
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'redisIndex' => $redisIndex,
            'instance' => $instance,
            'deploymentName' => $deploymentName,
            'workerDeploymentName' => $workerDeploymentName,
            'serviceName' => $serviceName,
            'ingressName' => $ingressName,
            'secretName' => $secretName,
            'oidcSecretName' => $oidcSecretName,
            'dbUser' => $dbUser,
            'dbName' => $dbName,
            'bucket' => $bucket,
            's3InternalEndpoint' => $s3Endpoints['internal'],
            's3PublicEndpoint' => $s3Endpoints['public'],
        ])->render();

        $tmp = sys_get_temp_dir()."/larakube-crm-twenty-{$instance}.yaml";
        file_put_contents($tmp, $manifest);

        // Twenty CRM's official entrypoint runs `yarn database:init:prod`/
        // `command:prod upgrade` on every boot (schema check + up to 200
        // workspace-upgrade steps) before the HTTP server ever starts — even
        // an already-migrated instance routinely takes several minutes past
        // most other tools' rollout windows. Confirmed live 2026-08-14: a
        // 180s timeout reported failure while the pod went on to boot
        // successfully on its own a few minutes later.
        $rolledOut = $this->withSpin(
            'Applying Twenty CRM manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $deploymentName, 420),
        );
        @unlink($tmp);

        if (! $rolledOut) {
            return 1;
        }

        // The manifest above already applied the worker Deployment too (same
        // file, one kubectl apply) — just verify its own rollout separately,
        // same pattern GlitchTip's web+worker split uses (ErrorsInitCommand).
        $workerRolledOut = $this->withSpin(
            'Waiting for the Twenty CRM worker...',
            fn () => Process::timeout(430)->run("{$kubectl} rollout status deployment/{$workerDeploymentName} -n ".escapeshellarg($ns).' --timeout=420s')->successful(),
        );

        if (! $workerRolledOut) {
            $this->laraKubeError("{$workerDeploymentName} never became Ready — check `kubectl get pods -n {$ns}`.");

            return 1;
        }

        $this->registerDeployedTool(ClusterTool::CRM, $kubectl, $host, instance: $instance);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Twenty CRM stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Database:</>                <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->line("  <fg=gray>Redis DB:</>                <fg=blue>{$redisIndex}</>");
        $this->line("  <fg=gray>Storage:</>                 <fg=blue>Commons {$s3Driver->getLabel()}</> · Bucket <fg=blue>{$bucket}</>");
        $this->newLine();
        $this->line('  <fg=gray>Twenty CRM has no env-based admin seeding — sign up at the URL above to create the first account.</>');
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::CRM);
    }
}
