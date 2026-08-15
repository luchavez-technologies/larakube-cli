<?php

namespace App\Commands\Design;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithDesign;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ReconcilesPenpotFlags;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class DesignInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithDesign, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, ReconcilesPenpotFlags, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected $signature = 'design:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=      : Target a specific kube-context}
        {--domain=       : Base domain OR full host for Penpot (example.com → prefix.example.com)}
        {--admin-email=  : Primary administrator email for Penpot}
        {--with-exporter : Also deploy the Penpot Exporter (Playwright/Chromium) container for PDF/PNG exports}
        {--vpn-only      : Restrict access via NetBird VPN IP whitelisting}
        {--force         : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Penpot design & prototyping suite into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployDesign();
    }

    protected function deployDesign(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->designKubectl($context);

        $domainOption = trim((string) ($this->option('domain') ?? ''));
        $host = $domainOption !== ''
            ? $this->sanitizeDomainInput($domainOption)
            : $this->resolveToolHost(SharedClusterService::DESIGN, ClusterTool::DESIGN, $env, $kubectl, 'main');
        $instance = ClusterTool::DESIGN->instanceSlugFromHost($host);

        $ns = $this->designNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');
        $withExporter = (bool) $this->option('with-exporter');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::DESIGN, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $this->ensureCommons(['postgres', 'redis'])) {
            return 1;
        }

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

        $s3Creds = $this->readCommonsS3Credentials();
        if ($s3Creds === null) {
            $this->laraKubeError('Commons S3 credentials not found. Re-run `larakube plex:init`.');

            return 1;
        }

        $s3Driver = StorageDriver::from($s3Service);
        $s3Bucket = ClusterTool::DESIGN->commonsBuckets($instance)[0];
        if (! $this->allocateStorageBucket($s3Driver, $s3Bucket)) {
            return 1;
        }

        $s3Endpoint = $this->resolveCommonsS3Endpoints($s3Driver, 'Penpot')['public'];

        $backendName = ClusterTool::DESIGN->deploymentName($instance);
        $frontendName = $instance === 'main' ? 'design-penpot-frontend' : "design-penpot-frontend-{$instance}";
        $exporterName = $instance === 'main' ? 'design-penpot-exporter' : "design-penpot-exporter-{$instance}";
        $serviceName = $instance === 'main' ? 'design' : "design-{$instance}";
        $backendServiceName = $instance === 'main' ? 'design-backend' : "design-backend-{$instance}";
        $exporterServiceName = $instance === 'main' ? 'design-exporter' : "design-exporter-{$instance}";
        $ingressName = $serviceName;
        $dbSecretName = $instance === 'main' ? 'design-penpot-secrets' : "design-penpot-secrets-{$instance}";
        $smtpSecretName = $instance === 'main' ? 'design-penpot-smtp' : "design-penpot-smtp-{$instance}";
        $oidcSecretName = $instance === 'main' ? 'design-penpot-oidc' : "design-penpot-oidc-{$instance}";
        $dbName = ClusterTool::DESIGN->commonsDatabases($instance)[0];
        $dbUser = $dbName;

        $adminEmail = $this->readDesignSecret($kubectl, $ns, 'admin-email', $instance) ?? $this->resolveAdminEmail($host);
        $dbPassword = $this->readDesignSecret($kubectl, $ns, 'password', $instance) ?? Str::random(24);
        // Once OpenBao's database secrets engine already owns this static
        // role, defer to ITS current password instead of re-affirming a
        // locally-cached one that may predate OpenBao's own rotation — see
        // resolveManagedDbPassword()'s docblock.
        $dbPassword = $this->resolveManagedDbPassword($kubectl, $dbName, $dbPassword);
        $secretKey = $this->readDesignSecret($kubectl, $ns, 'secret-key', $instance) ?? bin2hex(random_bytes(32));

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $redisIndex = $this->allocateCommonsRedisIndex($dbName);
        if ($redisIndex === null) {
            $this->laraKubeError('The Commons Valkey has no free logical DB index (all 16 in use).');

            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $clusterEnv = $env === 'local' ? 'dev' : $env;
        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbSecretName, $dbName, $dbPassword, $secretKey, $clusterEnv) {
            $cmd = "{$kubectl} create secret generic {$dbSecretName} -n {$ns} "
                .'--from-literal=password='.escapeshellarg($dbPassword).' '
                .'--from-literal=secret-key='.escapeshellarg($secretKey).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);

            if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
                if ($this->databaseEngineMounted($kubectl)) {
                    $this->registerStaticRole($kubectl, $dbName);
                    $realPassword = $this->readStaticRolePassword($kubectl, $dbName);
                    if ($realPassword !== null) {
                        Process::run(
                            "{$kubectl} patch secret {$dbSecretName} -n {$ns} --type=json "
                            .'-p=\'[{"op":"replace","path":"/data/password","value":"'.base64_encode($realPassword).'"}]\'',
                        );
                    }
                } else {
                    $this->pushClusterSecret($kubectl, "DESIGN_DB_PASSWORD_{$dbName}", $dbPassword, $clusterEnv);
                }
            }
        });

        $this->withSpin(
            'Ensuring baseline Penpot feature flags...',
            fn () => $this->ensureDesignBaselineFlags($kubectl, $ns, $oidcSecretName, $smtpSecretName, $backendName, $frontendName),
        );

        $manifest = view('k8s.design.shared', [
            'host' => $host,
            'instance' => $instance,
            'backendName' => $backendName,
            'frontendName' => $frontendName,
            'exporterName' => $exporterName,
            'serviceName' => $serviceName,
            'backendServiceName' => $backendServiceName,
            'exporterServiceName' => $exporterServiceName,
            'ingressName' => $ingressName,
            'dbSecretName' => $dbSecretName,
            'smtpSecretName' => $smtpSecretName,
            'oidcSecretName' => $oidcSecretName,
            'dbUser' => $dbUser,
            'dbName' => $dbName,
            'redisIndex' => $redisIndex,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'withExporter' => $withExporter,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            's3Endpoint' => $s3Endpoint,
            's3Bucket' => $s3Bucket,
            's3AccessKey' => $s3Creds['access'],
            's3SecretKey' => $s3Creds['secret'],
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-design-penpot-'.$instance.'.yaml';
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Penpot manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $backendName, 180),
        );
        @unlink($tmp);

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::DESIGN, $kubectl, $host, extra: ['adminEmail' => $adminEmail]);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Penpot design & prototyping suite is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Admin Email:</>             {$adminEmail}");
        $this->line("  <fg=gray>Database:</>                <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->line('  <fg=gray>Storage:</>                 <fg=blue>Commons S3</> · Bucket <fg=blue>'.$s3Bucket.'</>');
        $this->newLine();

        return 0;
    }

    /**
     * Reconcile design-penpot-oidc's PENPOT_FLAGS to ClusterTool::DESIGN's
     * current baselineFlags() plus whatever integrations are verifiably
     * still wired — recomputed from scratch every run via
     * ReconcilesPenpotFlags, not unioned with whatever string happened to
     * be there before. See docs/decisions/0013-design-init-idempotent-flags.md.
     */
    protected function ensureDesignBaselineFlags(string $kubectl, string $ns, string $oidcSecretName, string $smtpSecretName, string $backendName, string $frontendName): void
    {
        $value = $this->resolveDesignPenpotFlags($kubectl, $ns, $oidcSecretName, $smtpSecretName, null, $backendName);

        $this->applyDesignPenpotFlags($kubectl, $ns, $oidcSecretName, $value, $backendName, $frontendName);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::DESIGN);
    }

    /** Resolve the admin email for Penpot */
    protected function resolveAdminEmail(string $host): string
    {
        $parts = explode('.', $host);
        $default = 'admin@'.(count($parts) >= 2 ? implode('.', array_slice($parts, 1)) : $host);

        return $this->flagOrPrompt(
            flag: 'admin-email',
            prompt: fn () => \Laravel\Prompts\text(
                label: 'Primary administrator email for Penpot',
                default: $default,
                required: true,
            ),
            purpose: 'Primary administrator email for Penpot',
            example: "--admin-email={$default}",
        );
    }
}
