<?php

namespace App\Commands\Sign;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\RenderDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSign;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class SignInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithSign, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected $signature = 'sign:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Sign (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Documenso electronic signature stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySign();
    }

    protected function deploySign(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = Kubectl::forContext($context)->prefix();
        $host = $this->resolveToolHost(SharedClusterService::SIGN, ClusterTool::SIGN, $env, $kubectl);
        $names = ToolInstance::forHost(ClusterTool::SIGN, $host);

        $ns = $names->namespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::SIGN, $kubectl, $names->instance)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Documenso stores signed PDFs in object storage. Default it onto the
        // Commons SeaweedFS (MinIO fallback) instead of its `database` upload
        // transport, which would otherwise bloat Commons Postgres with blobs.
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

        // Headless Chrome renders each document's certificate page when it's
        // sealed. The Documenso image ships no browser, so without it every
        // document stays pending forever.
        if (! $this->ensureCommons(['postgres', $s3Service, RenderDriver::HEADLESS_CHROME->value])) {
            return 1;
        }

        $browserIp = $this->commonsServiceClusterIp(RenderDriver::HEADLESS_CHROME->value);
        if ($browserIp === null) {
            $this->laraKubeError('Could not find the Commons headless Chrome service. Re-run `larakube plex:init`.');

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

        // NEXT_PRIVATE_UPLOAD_ENDPOINT is Documenso's ONE S3 endpoint —
        // Documenso has no separate internal/public split (unlike Teable or
        // Sendrec), and its client bundle signs presigned upload/download
        // URLs straight into the browser (NEXT_PUBLIC_UPLOAD_TRANSPORT=s3 is
        // shipped client-side). Cluster-internal DNS here would make every
        // document upload/view fail to resolve — must be the public endpoint.
        $s3Endpoint = $this->resolveCommonsS3Endpoints($s3Driver, 'Documenso')['public'];

        $dbPassword = $this->readSignSecret($kubectl, $names, 'db-password') ?? Str::random(24);
        $nextauthSecret = $this->readSignSecret($kubectl, $names, 'nextauth-secret') ?? bin2hex(random_bytes(32));
        $encryptionKey = $this->readSignSecret($kubectl, $names, 'encryption-key') ?? bin2hex(random_bytes(32));
        $encryptionSecondaryKey = $this->readSignSecret($kubectl, $names, 'encryption-secondary-key') ?? bin2hex(random_bytes(32));

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

        $secret = $names->secret();
        $clusterEnv = $env === 'local' ? 'dev' : $env;
        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $secret, $dbName, $dbPassword, $nextauthSecret, $encryptionKey, $encryptionSecondaryKey, $s3Creds, $clusterEnv): void {
            Kubectl::fromPrefix($kubectl)->putSecret($ns, $secret, ['db-password' => $dbPassword, 'nextauth-secret' => $nextauthSecret, 'encryption-key' => $encryptionKey, 'encryption-secondary-key' => $encryptionSecondaryKey, 's3-access-key' => $s3Creds['access'], 's3-secret-key' => $s3Creds['secret']]);

            if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
                if ($this->databaseEngineMounted($kubectl)) {
                    $this->registerStaticRole($kubectl, $dbName);

                    $realPassword = $this->readStaticRolePassword($kubectl, $dbName);
                    if ($realPassword !== null) {
                        Kubectl::fromPrefix($kubectl)->patchSecret($ns, $secret, ['db-password' => $realPassword]);
                    }
                } else {
                    $this->pushClusterSecret($kubectl, 'SIGN_DB_PASSWORD', $dbPassword, $clusterEnv);
                }
                $this->pushClusterSecret($kubectl, 'SIGN_NEXTAUTH_SECRET', $nextauthSecret, $clusterEnv);
                $this->pushClusterSecret($kubectl, 'SIGN_ENCRYPTION_KEY', $encryptionKey, $clusterEnv);
                $this->pushClusterSecret($kubectl, 'SIGN_ENCRYPTION_SECONDARY_KEY', $encryptionSecondaryKey, $clusterEnv);
            }
        });

        if (! $this->withSpin('Ensuring the document signing certificate...', fn () => $this->ensureSignSigningCert($kubectl, $names))) {
            $this->laraKubeError('Could not create the document signing certificate. Is `openssl` installed?');

            return 1;
        }

        $manifest = view('k8s.sign.shared', [
            'names' => $names,
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            's3Endpoint' => $s3Endpoint,
            'browserlessUrl' => "http://{$browserIp}:".RenderDriver::HEADLESS_CHROME->port(),
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-sign-documenso.yaml');
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Documenso manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $names->deployment(), 180),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::SIGN, $kubectl, $host, $names->instance);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Documenso signature stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Database:</>                <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::SIGN);
    }
}
