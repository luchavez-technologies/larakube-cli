<?php

namespace App\Commands\Directus;

use App\Commands\Tool\AbstractToolInitCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SecretKind;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
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

use function Laravel\Prompts\text;

use Spatie\TemporaryDirectory\TemporaryDirectory;

abstract class DirectusInitCommand extends AbstractToolInitCommand
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithData, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithSecrets, InteractsWithVolumeSizing, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected function runInit(): int
    {
        return $this->deployDirectus();
    }

    protected function deployDirectus(): int
    {
        $engine = 'directus';
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->dataNamespace();

        [$host, $instance] = $this->resolveInstanceAwareHost(SharedClusterService::DATA, ClusterTool::DIRECTUS, $env, $kubectl, 'Directus');
        $aliasHosts = $this->resolveToolAliasHosts($kubectl, ClusterTool::DIRECTUS, $instance);
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->assertVpnOnlySupported(ClusterTool::DIRECTUS)) {
            return 1;
        }

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::DIRECTUS, $kubectl, $instance, $engine)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $s3Creds = $this->readCommonsS3Credentials();
        $s3Key = $s3Creds['access'] ?? 'seaweedfs-access-key';
        $s3Secret = $s3Creds['secret'] ?? 'seaweedfs-secret-key';

        $names = ToolInstance::forInstance(ClusterTool::DIRECTUS, $instance, $engine);
        $secretName = $names->secret();
        $smtpSecretName = $names->secret(SecretKind::SMTP);
        $oidcSecretName = $names->secret(SecretKind::OIDC);
        $deployName = $names->deployment();
        $labels = $names->labels();

        foreach (['pocketbase', 'wordpress'] as $otherEngine) {
            $otherTool = $otherEngine === 'pocketbase' ? ClusterTool::POCKETBASE : ClusterTool::WORDPRESS;
            $otherNames = ToolInstance::forInstance($otherTool, $instance, $otherEngine);
            if ($this->deploymentExists($kubectl, $ns, $otherNames->deployment())) {
                $otherLabel = $otherEngine === 'pocketbase' ? 'PocketBase' : 'WordPress';
                if ($this->cannotPrompt()) {
                    $this->laraKubeError("Host '{$host}' is already in use by {$otherLabel}. Pass a different --domain or remove {$otherLabel} first with 'larakube {$otherEngine}:remove --domain={$host}'.");

                    return 1;
                }

                $this->laraKubeWarn("Host '{$host}' is already in use by {$otherLabel}.");
                $newHost = text(
                    label: 'What host should Directus use instead?',
                    placeholder: 'e.g. data2.example.com',
                    required: true,
                );
                $host = $this->sanitizeDomainInput($newHost);
                $instance = $this->resolveInstanceForDomain($kubectl, ClusterTool::DIRECTUS, $host);
                $names = ToolInstance::forInstance(ClusterTool::DIRECTUS, $instance, $engine);
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

        $adminEmail = $this->readDataSecret($kubectl, $ns, 'admin-email', $instance, $engine) ?? $this->resolveAdminEmail($host, 'Directus');

        if (! $this->ensureCommons(['postgres', 'redis', 'seaweedfs'])) {
            return 1;
        }

        $dbName = $this->tool()->commonsDatabases($instance, $engine)[0] ?? 'data_directus';
        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $redisIndex = $this->allocateCommonsRedisIndex($dbName);

        $bucket = $this->tool()->commonsBuckets($instance, $engine)[0] ?? 'data-directus-storage';
        if (! $this->allocateStorageBucket(StorageDriver::SEAWEEDFS, $bucket)) {
            return 1;
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
            $prefix = 'DATA_DIRECTUS_'.strtoupper(str_replace('-', '_', $instance));

            $this->pushClusterSecret($kubectl, "{$prefix}_SECRET", $secret, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_KEY", $key, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_ADMIN_EMAIL", $adminEmail, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_ADMIN_PASSWORD", $adminPassword, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_S3_KEY", $s3Key, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_S3_SECRET", $s3Secret, $env);
            $this->pushClusterSecret($kubectl, "{$prefix}_DB_PASSWORD", $dbPassword, $env);
        }

        $ssoWired = $this->readClusterSecretKey($kubectl, $ns, $oidcSecretName, 'AUTH_ZITADEL_CLIENT_ID') !== null;

        $manifest = view('k8s.data.shared', [
            'volumeSize' => $this->volumeSizeResolver($kubectl, $ns),
            'engine' => $engine,
            'dbEngine' => 'postgresql',
            'instance' => $instance,
            'deployName' => $deployName,
            'labels' => $labels,
            'secretName' => $secretName,
            'smtpSecretName' => $smtpSecretName,
            'oidcSecretName' => $oidcSecretName,
            'dbName' => $dbName,
            'dbHost' => '127.0.0.1',
            'dbUser' => $dbName,
            'bucket' => $bucket,
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
            'Applying Directus manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $deployName, 180, tool: ClusterTool::DIRECTUS),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::DIRECTUS, $kubectl, $host, $instance, ['adminEmail' => $adminEmail, 'engine' => $engine, 'db' => 'postgresql']);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Directus Data / Headless CMS stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>      <fg=blue>https://{$host}</>");
        foreach ($aliasHosts as $aliasHost) {
            $this->line("  <fg=gray>Alias:</>           <fg=blue>https://{$aliasHost}</>");
        }
        $this->line("  <fg=gray>Admin Email:</>     <fg=blue>{$adminEmail}</>");
        $this->line("  <fg=gray>Admin Password:</>  <fg=yellow>{$adminPassword}</>");
        $this->line("  <fg=gray>Database:</>        <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->line("  <fg=gray>Redis DB:</>        <fg=blue>{$redisIndex}</>");
        $this->line("  <fg=gray>Storage:</>         <fg=blue>SeaweedFS S3</> · Bucket <fg=blue>{$bucket}</>");
        $this->newLine();

        return 0;
    }

    protected function deploymentExists(string $kubectl, string $namespace, string $deployment): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeployment($namespace, $deployment);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::DIRECTUS);
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
