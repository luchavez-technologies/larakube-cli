<?php

namespace App\Commands\Record;

use App\Commands\Tool\AbstractToolInitCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithRecord;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\TemporaryDirectory\TemporaryDirectory;

abstract class RecordInitCommand extends AbstractToolInitCommand
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithRecord, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected function runInit(): int
    {
        return $this->deployRecord();
    }

    protected function deployRecord(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = Kubectl::forContext($context)->prefix();
        $host = $this->resolveToolHost(SharedClusterService::RECORD, ClusterTool::RECORD, $env, $kubectl);

        $ns = $this->recordNamespace();
        $names = ToolInstance::forHost(ClusterTool::RECORD, $host);
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::RECORD, $kubectl, $names->instance)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $this->ensureCommons(['postgres'])) {
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
        $s3Bucket = $names->bucket();
        if (! $this->allocateStorageBucket($s3Driver, $s3Bucket)) {
            return 1;
        }

        // Sendrec supports a distinct S3_PUBLIC_ENDPOINT for presigned
        // upload/playback URLs it hands to the browser, separate from
        // S3_ENDPOINT (its own server-to-S3 calls) — so unlike Outline/
        // Documenso, the internal cluster-DNS endpoint stays in place and
        // only the browser-facing one needs to be public.
        $s3Endpoints = $this->resolveCommonsS3Endpoints($s3Driver, 'Sendrec');
        $s3Endpoint = $s3Endpoints['internal'];
        $s3PublicEndpoint = $s3Endpoints['public'];

        $dbPassword = $this->readRecordSecret($kubectl, $ns, $names->secret(), 'db-password') ?? Str::random(24);
        $jwtSecret = $this->readRecordSecret($kubectl, $ns, $names->secret(), 'jwt-secret') ?? bin2hex(random_bytes(32));

        $dbName = $names->database();
        // Once OpenBao's database secrets engine already owns this static
        // role, defer to ITS current password instead of re-affirming a
        // locally-cached one that may predate OpenBao's own rotation — see
        // resolveManagedDbPassword()'s docblock.
        $dbPassword = $this->resolveManagedDbPassword($kubectl, $dbName, $dbPassword);

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $clusterEnv = $env === 'local' ? 'dev' : $env;
        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $names, $dbPassword, $jwtSecret, $clusterEnv): void {
            Kubectl::fromPrefix($kubectl)->putSecret($ns, $names->secret(), ['db-password' => $dbPassword, 'jwt-secret' => $jwtSecret]);

            if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
                // Rotation is wired by `secrets:wire`, which also creates the ExternalSecret
                // that carries each rotated password back into this Secret. Registering the
                // role here would rotate it with nothing to sync the new password.
                if (! $this->databaseEngineMounted($kubectl)) {
                    $this->pushClusterSecret($kubectl, 'RECORD_DB_PASSWORD', $dbPassword, $clusterEnv);
                }
                $this->pushClusterSecret($kubectl, 'RECORD_JWT_SECRET', $jwtSecret, $clusterEnv);
                // NOT syncClusterSecretToNamespace() here — same bug that took
                // down Zitadel (confirmed live 2026-08-02): it extracts KV
                // path "{env}" as one object, but every value above is at the
                // deeper "{env}/{KEY}" path, so it always syncs empty and, as
                // an Owner-mode ExternalSecret with a 1m refresh, wipes the
                // `create secret` above on its next reconcile. openbao:init's
                // own sweep (tool-es.blade.php) is the correct, working path.
            }
        });

        $manifest = view('k8s.record.shared', [
            'host' => $host,
            'instance' => $names->instance,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            's3Endpoint' => $s3Endpoint,
            's3PublicEndpoint' => $s3PublicEndpoint,
            's3Bucket' => $s3Bucket,
            'dbName' => $dbName,
            's3AccessKey' => $s3Creds['access'],
            's3SecretKey' => $s3Creds['secret'],
            // The blade reads this to set REGISTRATION_ENABLED. It was never
            // passed, so it always fell back to false — and since sendrec:init
            // seeds no admin and Sendrec's users table has no role column,
            // that shipped an instance with zero accounts and no way to make
            // one. Open it for the first sign-up, then re-run without the flag.
            'allowRegistration' => (bool) $this->option('allow-registration'),
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-sendrec.yaml');
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Sendrec manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $names->deployment(), 180),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::RECORD, $kubectl, $host, $names->instance);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Sendrec async video platform stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Database:</>    <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::RECORD);
    }
}
