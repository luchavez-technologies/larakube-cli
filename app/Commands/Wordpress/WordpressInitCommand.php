<?php

namespace App\Commands\Wordpress;

use App\Commands\Tool\AbstractToolInitCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SecretKind;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithData;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSecrets;
use App\Traits\InteractsWithVolumeSizing;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use Spatie\TemporaryDirectory\TemporaryDirectory;

abstract class WordpressInitCommand extends AbstractToolInitCommand
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithData, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithSecrets, InteractsWithVolumeSizing, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected function runInit(): int
    {
        return $this->deployWordpress();
    }

    protected function deployWordpress(): int
    {
        $engine = 'wordpress';
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->dataNamespace();

        [$host, $instance] = $this->resolveInstanceAwareHost(SharedClusterService::DATA, ClusterTool::WORDPRESS, $env, $kubectl, 'WordPress');
        $aliasHosts = $this->resolveToolAliasHosts($kubectl, ClusterTool::WORDPRESS, $instance);
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->assertVpnOnlySupported(ClusterTool::WORDPRESS)) {
            return 1;
        }

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::WORDPRESS, $kubectl, $instance, $engine)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $s3Creds = $this->readCommonsS3Credentials();
        $s3Key = $s3Creds['access'] ?? 'seaweedfs-access-key';
        $s3Secret = $s3Creds['secret'] ?? 'seaweedfs-secret-key';

        $names = ToolInstance::forInstance(ClusterTool::WORDPRESS, $instance, $engine);
        $secretName = $names->secret();
        $smtpSecretName = $names->secret(SecretKind::SMTP);
        $oidcSecretName = $names->secret(SecretKind::OIDC);
        $deployName = $names->deployment();
        $labels = $names->labels();

        foreach (['pocketbase', 'directus'] as $otherEngine) {
            $otherTool = $otherEngine === 'pocketbase' ? ClusterTool::POCKETBASE : ClusterTool::DIRECTUS;
            $otherNames = ToolInstance::forInstance($otherTool, $instance, $otherEngine);
            if ($this->deploymentExists($kubectl, $ns, $otherNames->deployment())) {
                $otherLabel = $otherEngine === 'pocketbase' ? 'PocketBase' : 'Directus';
                if ($this->cannotPrompt()) {
                    $this->laraKubeError("Host '{$host}' is already in use by {$otherLabel}. Pass a different --domain or remove {$otherLabel} first with 'larakube {$otherEngine}:remove --domain={$host}'.");

                    return 1;
                }

                $this->laraKubeWarn("Host '{$host}' is already in use by {$otherLabel}.");
                $newHost = text(
                    label: 'What host should WordPress use instead?',
                    placeholder: 'e.g. data2.example.com',
                    required: true,
                );
                $host = $this->sanitizeDomainInput($newHost);
                $instance = $this->resolveInstanceForDomain($kubectl, ClusterTool::WORDPRESS, $host);
                $names = ToolInstance::forInstance(ClusterTool::WORDPRESS, $instance, $engine);
                $secretName = $names->secret();
                $smtpSecretName = $names->secret(SecretKind::SMTP);
                $oidcSecretName = $names->secret(SecretKind::OIDC);
                $deployName = $names->deployment();
                $labels = $names->labels();
                break;
            }
        }

        $secret = $this->readDataSecret($kubectl, $ns, 'secret', $instance, $engine) ?? Str::uuid()->toString();
        $key = $this->readDataSecret($kubectl, $ns, 'key', $instance, $engine) ?? Str::uuid()->toString();
        $dbPassword = $this->readDataSecret($kubectl, $ns, 'db-password', $instance, $engine) ?? Str::random(24);
        $adminPassword = $this->readDataSecret($kubectl, $ns, 'admin-password', $instance, $engine) ?? Str::random(24);

        $adminEmail = $this->readDataSecret($kubectl, $ns, 'admin-email', $instance, $engine) ?? $this->resolveAdminEmail($host, 'WordPress');

        $dbChoice = $this->hasOption('db') ? strtolower((string) ($this->option('db') ?? '')) : null;
        $noPlex = (bool) ($this->hasOption('no-plex') && $this->option('no-plex'));

        if ($dbChoice === 'mysql' || $dbChoice === 'mariadb') {
            $dbEngine = 'mysql';
        } elseif ($dbChoice === 'sqlite') {
            $dbEngine = 'sqlite';
        } elseif (! $this->cannotPrompt()) {
            $dbEngine = select(
                label: 'Which database engine for WordPress?',
                options: [
                    'sqlite' => 'SQLite — Zero-container lightweight storage (<150MB RAM, Recommended for blogs/sites)',
                    'mysql' => 'MySQL — Shared Plex Commons or Dedicated database container',
                ],
                default: 'sqlite',
            );
        } else {
            $dbEngine = 'sqlite';
        }

        if ($dbEngine === 'mysql') {
            if (! $noPlex && ! $this->ensureCommons(['mysql'])) {
                return 1;
            }

            $dbName = $this->tool()->commonsDatabases($instance, $engine)[0] ?? 'data_wordpress';
            if (! $noPlex && ! $this->allocateDatabase(DatabaseDriver::MYSQL, $dbName, $dbPassword)) {
                return 1;
            }

            $redisIndex = $this->allocateCommonsRedisIndex($dbName);
        } else {
            $dbName = 'SQLite (embedded)';
            $redisIndex = null;
        }

        $pvcName = $names->volume();
        $configMapName = $names->configMap('hooks');

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Kubectl::fromPrefix($kubectl)->apply((string) json_encode([
            'apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => $ns],
        ])));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $secretName, $secret, $key, $dbPassword, $adminEmail, $adminPassword, $s3Key, $s3Secret, $labels): void {
            Kubectl::fromPrefix($kubectl)->putSecret($ns, $secretName, ['secret' => $secret, 'key' => $key, 'db-password' => $dbPassword, 'admin-email' => $adminEmail, 'admin-password' => $adminPassword, 's3-key' => $s3Key, 's3-secret' => $s3Secret], $labels);
        });

        if ($this->secretsBackendAvailable($kubectl)) {
            $prefix = 'DATA_WORDPRESS_'.strtoupper(str_replace('-', '_', $instance));

            $this->pushClusterSecret($kubectl, "{$prefix}_SECRET", $secret, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_KEY", $key, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_ADMIN_EMAIL", $adminEmail, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_ADMIN_PASSWORD", $adminPassword, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_S3_KEY", $s3Key, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_S3_SECRET", $s3Secret, $env);

            if ($dbEngine === 'mysql') {
                $this->pushClusterSecret($kubectl, "{$prefix}_DB_PASSWORD", $dbPassword, $env);
            }
        }

        $ssoWired = $this->readClusterSecretKey($kubectl, $ns, $oidcSecretName, 'AUTH_ZITADEL_CLIENT_ID') !== null;

        $manifest = view('k8s.wordpress.wordpress', [
            'volumeSize' => $this->volumeSizeResolver($kubectl, $ns),
            'engine' => $engine,
            'dbEngine' => $dbEngine,
            'instance' => $instance,
            'deployName' => $deployName,
            'labels' => $labels,
            'secretName' => $secretName,
            'smtpSecretName' => $smtpSecretName,
            'oidcSecretName' => $oidcSecretName,
            'dbName' => $dbName,
            'dbHost' => ($dbEngine === 'mysql') ? "mysql.{$this->plexNamespace()}.svc.cluster.local:3306" : '127.0.0.1',
            'dbUser' => $dbName,
            'bucket' => null,
            'pvcName' => $pvcName,
            'configMapName' => $configMapName,
            'host' => $host,
            'aliasHosts' => $aliasHosts,
            'namespace' => $ns,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'redisIndex' => $redisIndex,
            'authProviders' => $ssoWired ? 'local,zitadel' : 'local',
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path("larakube-{$deployName}.yaml");
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying WordPress manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $deployName, 180, tool: ClusterTool::WORDPRESS),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::WORDPRESS, $kubectl, $host, $instance, ['adminEmail' => $adminEmail, 'engine' => $engine, 'db' => $dbEngine]);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ WordPress Data / Headless CMS stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>      <fg=blue>https://{$host}/wp-admin/</>");
        foreach ($aliasHosts as $aliasHost) {
            $this->line("  <fg=gray>Alias:</>           <fg=blue>https://{$aliasHost}/wp-admin/</>");
        }
        $this->line("  <fg=gray>Admin Email:</>     <fg=blue>{$adminEmail}</>");
        $this->line("  <fg=gray>Admin Password:</>  <fg=yellow>{$adminPassword}</>");
        if ($dbEngine === 'mysql') {
            $this->line("  <fg=gray>Database:</>        <fg=blue>Commons MySQL</> · DB <fg=blue>{$dbName}</>");
            if ($redisIndex !== null) {
                $this->line("  <fg=gray>Redis Cache:</>     <fg=blue>{$redisIndex}</>");
            }
        } else {
            $this->line("  <fg=gray>Database:</>        <fg=blue>Embedded SQLite</> · PVC <fg=blue>{$pvcName}</>");
        }
        $this->newLine();

        return 0;
    }

    protected function deploymentExists(string $kubectl, string $namespace, string $deployment): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeployment($namespace, $deployment);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::WORDPRESS);
    }

    /** Resolve the admin email for a Data tool (Directus / PocketBase / WordPress) */
    protected function resolveAdminEmail(string $host, string $toolLabel): string
    {
        $parts = explode('.', $host);
        $default = 'admin@'.(count($parts) >= 2 ? implode('.', array_slice($parts, 1)) : $host);

        return $this->flagOrPrompt(
            flag: 'admin-email',
            prompt: fn () => \Laravel\Prompts\text(
                label: "Admin email for {$toolLabel}",
                default: $default,
                required: true,
            ),
            purpose: "Admin email for {$toolLabel}",
            example: "--admin-email={$default}",
        );
    }
}
