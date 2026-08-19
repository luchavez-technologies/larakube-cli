<?php

namespace App\Commands\Sheet;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSheet;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class SheetsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithSheet, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

    protected $signature = 'sheets:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Sheet (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy Teable (spreadsheet database) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySheet();
    }

    protected function deploySheet(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->sheetKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::SHEET, ClusterTool::SHEETS, $env, $kubectl);

        $ns = $this->sheetNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::SHEETS, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $this->ensureCommons(['postgres', 'redis'])) {
            return 1;
        }

        // Keep secrets stable across re-runs: rotating SECRET_KEY would
        // invalidate every Teable session and signed token.
        $dbPassword = $this->readSheetDbPassword($kubectl, $ns) ?? Str::random(24);
        $secretKey = $this->readSheetSecret($kubectl, $ns, 'secret-key') ?? Str::random(50);

        $storage = $this->resolveSheetStorage();
        if ($storage === null) {
            return 1;
        }

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, 'teable', $dbPassword)) {
            return 1;
        }

        $redisIndex = $this->allocateCommonsRedisIndex('teable');
        if ($redisIndex === null) {
            $this->laraKubeError('The Commons Valkey has no free logical DB index (all 16 in use).');

            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // Pre-compute the full Prisma connection string so the blade template
        // can read it directly from the Secret. Kubernetes does NOT expand
        // $(VAR) in env value fields — Prisma would see the literal string.
        $plexNs = $this->plexNamespace();
        $dbUrl = "postgresql://teable:{$dbPassword}@postgres.{$plexNs}.svc.cluster.local:5432/teable";

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $secretKey, $storage, $dbUrl): void {
            Process::run(
                "{$kubectl} create secret generic sheet-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=secret-key='.escapeshellarg($secretKey).' '
                .'--from-literal=s3-access-key='.escapeshellarg($storage['access']).' '
                .'--from-literal=s3-secret-key='.escapeshellarg($storage['secret']).' '
                .'--from-literal=database-url='.escapeshellarg($dbUrl).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $manifest = view('k8s.sheet.teable', [
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'redisIndex' => $redisIndex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            's3PublicEndpoint' => $storage['publicEndpoint'],
            's3InternalEndpoint' => $storage['internalEndpoint'],
            's3PublicBucket' => $storage['publicBucket'],
            's3PrivateBucket' => $storage['privateBucket'],
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-sheet.yaml');
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Sheet (Teable) manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, 'sheet-teable', 300),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::SHEETS, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Sheet (Teable) stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Cache/queue:</>             <fg=blue>Commons Valkey</> · logical DB index <fg=blue>'.$redisIndex.'</>');
        $this->line('  <fg=gray>Object storage:</>          <fg=blue>'.$storage['publicEndpoint'].'</> · buckets <fg=blue>'
            .$storage['publicBucket'].'</>, <fg=blue>'.$storage['privateBucket'].'</>');
        $this->newLine();

        return 0;
    }

    /**
     * Resolve everything Teable needs to talk to the Commons object store:
     * shared credentials, its two buckets, and the endpoint pair.
     *
     * Teable presigns every attachment URL — including the "public" bucket —
     * so the Commons never needs an anonymous S3 identity (adding one would
     * open every OTHER tenant's bucket too, since SeaweedFS grants actions
     * cluster-wide, not per-bucket). What it does need is for the presigning
     * endpoint to be the host a browser can actually reach.
     *
     * @return array{access: string, secret: string, publicEndpoint: string, internalEndpoint: string, publicBucket: string, privateBucket: string}|null
     */
    protected function resolveSheetStorage(): ?array
    {
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
            return null;
        }

        $creds = $this->readCommonsS3Credentials();
        if ($creds === null) {
            $this->laraKubeError('Commons S3 credentials not found. Re-run `larakube plex:init`.');

            return null;
        }

        $driver = StorageDriver::from($s3Service);
        $internalEndpoint = "http://{$s3Service}.{$this->plexNamespace()}.svc.cluster.local:{$driver->port()}";

        $publicBucket = 'sheet-public';
        $privateBucket = 'sheet-private';

        if (! $this->allocateStorageBucket($driver, $publicBucket)
            || ! $this->allocateStorageBucket($driver, $privateBucket)) {
            return null;
        }

        // The Commons only has a browser-reachable S3 host if plex:init was
        // given one. Without it Teable still works server-side, but every
        // attachment link points at cluster DNS and 404s in the browser — so
        // say so plainly rather than shipping a half-working install.
        // Re-read: ensureCommons() may have just created or extended the spec.
        $liveSpec = $this->getCommonsSpec() ?? [];
        $publicHost = $liveSpec['services'][$s3Service]['host'] ?? null;
        $publicEndpoint = $publicHost !== null && $publicHost !== ''
            ? 'https://'.$publicHost
            : $internalEndpoint;

        if (! $publicHost) {
            $this->laraKubeWarn(
                "The Commons '{$s3Service}' has no public host, so Teable's attachment links will not "
                .'resolve from a browser. Set one with `larakube plex:init --s3-host=files.example.com`.',
            );
        }

        return [
            'access' => $creds['access'],
            'secret' => $creds['secret'],
            'publicEndpoint' => $publicEndpoint,
            'internalEndpoint' => $internalEndpoint,
            'publicBucket' => $publicBucket,
            'privateBucket' => $privateBucket,
        ];
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::SHEETS);
    }
}
