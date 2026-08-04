<?php

namespace App\Commands\Sign;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSign;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class SignInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithSign, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

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
        $kubectl = $this->signKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::SIGN, ClusterTool::SIGN, $env, $kubectl);

        $ns = $this->signNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::SIGN, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $this->ensureCommons(['postgres'])) {
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
        if (! $this->ensureCommons([$s3Service])) {
            return 1;
        }

        $s3Creds = $this->readCommonsS3Credentials();
        if ($s3Creds === null) {
            $this->laraKubeError('Commons S3 credentials not found. Re-run `larakube plex:init`.');

            return 1;
        }
        $s3Driver = StorageDriver::from($s3Service);
        $s3Bucket = 'sign-storage';
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

        $dbPassword = $this->readSignSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $nextauthSecret = $this->readSignSecret($kubectl, $ns, 'nextauth-secret') ?? bin2hex(random_bytes(32));
        $encryptionKey = $this->readSignSecret($kubectl, $ns, 'encryption-key') ?? bin2hex(random_bytes(32));
        $encryptionSecondaryKey = $this->readSignSecret($kubectl, $ns, 'encryption-secondary-key') ?? bin2hex(random_bytes(32));

        $dbName = 'sign_documenso';

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $clusterEnv = $env === 'local' ? 'dev' : $env;
        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbName, $dbPassword, $nextauthSecret, $encryptionKey, $encryptionSecondaryKey, $clusterEnv) {
            $cmd = "{$kubectl} create secret generic sign-documenso-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=nextauth-secret='.escapeshellarg($nextauthSecret).' '
                .'--from-literal=encryption-key='.escapeshellarg($encryptionKey).' '
                .'--from-literal=encryption-secondary-key='.escapeshellarg($encryptionSecondaryKey).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);

            if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
                if ($this->databaseEngineMounted($kubectl)) {
                    $this->registerStaticRole($kubectl, $dbName, 'plex-postgres', $dbName);

                    // registerStaticRole() rotates the password as a side
                    // effect the instant a role is FIRST created — the
                    // literal $dbPassword the Secret above already has is
                    // stale from that moment on. Same class of bug that
                    // desynced Zitadel, confirmed live 2026-08-02.
                    $realPassword = $this->readStaticRolePassword($kubectl, $dbName);
                    if ($realPassword !== null) {
                        Process::run(
                            "{$kubectl} patch secret sign-documenso-secrets -n {$ns} --type=json "
                            .'-p=\'[{"op":"replace","path":"/data/db-password","value":"'.base64_encode($realPassword).'"}]\'',
                        );
                    }
                } else {
                    $this->pushClusterSecret($kubectl, 'SIGN_DB_PASSWORD', $dbPassword, $clusterEnv);
                }
                $this->pushClusterSecret($kubectl, 'SIGN_NEXTAUTH_SECRET', $nextauthSecret, $clusterEnv);
                $this->pushClusterSecret($kubectl, 'SIGN_ENCRYPTION_KEY', $encryptionKey, $clusterEnv);
                $this->pushClusterSecret($kubectl, 'SIGN_ENCRYPTION_SECONDARY_KEY', $encryptionSecondaryKey, $clusterEnv);
                // NOT syncClusterSecretToNamespace() here — same bug that
                // took down Zitadel (confirmed live 2026-08-02): it extracts
                // KV path "{env}" as one object, but every value above is at
                // the deeper "{env}/{KEY}" path, so it always syncs empty
                // and, as an Owner-mode ExternalSecret with a 1m refresh,
                // wipes the `create secret` above on its next reconcile.
                // secrets:init's own sweep (tool-es.blade.php) is the
                // correct, working path.
            }
        });

        $manifest = view('k8s.sign.shared', [
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            's3Endpoint' => $s3Endpoint,
            's3Bucket' => $s3Bucket,
            's3AccessKey' => $s3Creds['access'],
            's3SecretKey' => $s3Creds['secret'],
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-sign-documenso.yaml';
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Documenso manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, 'sign-documenso', 180),
        );
        @unlink($tmp);

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::SIGN, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Documenso signature stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Database:</>    <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::SIGN);
    }
}
