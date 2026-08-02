<?php

namespace App\Commands\Record;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithRecord;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class RecordInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithRecord, LaraKubeOutput, ResolvesToolEnvironment, StreamsProcessOutput, SyncsClusterSecrets;

    protected $signature = 'record:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Sendrec (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--allow-registration : Open public sign-up (needed once to create the first account, then re-run without it)}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Sendrec async video platform stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployRecord();
    }

    protected function deployRecord(): int
    {
        $env = $this->resolveEnvironment();
        $host = $this->resolveRecordHost($env);

        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->recordKubectl($context);
        $ns = $this->recordNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::RECORD, $kubectl)) {
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
        $s3Bucket = 'record-storage';
        $s3Endpoint = "http://{$s3Service}.{$this->plexNamespace()}.svc.cluster.local:{$s3Driver->port()}";
        if (! $this->allocateStorageBucket($s3Driver, $s3Bucket)) {
            return 1;
        }

        $dbPassword = $this->readRecordSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $jwtSecret = $this->readRecordSecret($kubectl, $ns, 'jwt-secret') ?? bin2hex(random_bytes(32));

        $dbName = 'record_sendrec';

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $clusterEnv = $env === 'local' ? 'dev' : $env;
        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbName, $dbPassword, $jwtSecret, $clusterEnv) {
            $cmd = "{$kubectl} create secret generic record-sendrec-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=jwt-secret='.escapeshellarg($jwtSecret).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);

            if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
                if ($this->databaseEngineMounted($kubectl)) {
                    $this->registerStaticRole($kubectl, $dbName, 'plex-postgres', $dbName);
                } else {
                    $this->pushClusterSecret($kubectl, 'RECORD_DB_PASSWORD', $dbPassword, $clusterEnv);
                }
                $this->pushClusterSecret($kubectl, 'RECORD_JWT_SECRET', $jwtSecret, $clusterEnv);
                // NOT syncClusterSecretToNamespace() here — same bug that took
                // down Zitadel (confirmed live 2026-08-02): it extracts KV
                // path "{env}" as one object, but every value above is at the
                // deeper "{env}/{KEY}" path, so it always syncs empty and, as
                // an Owner-mode ExternalSecret with a 1m refresh, wipes the
                // `create secret` above on its next reconcile. secrets:init's
                // own sweep (tool-es.blade.php) is the correct, working path.
            }
        });

        $manifest = view('k8s.record.shared', [
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            's3Endpoint' => $s3Endpoint,
            's3Bucket' => $s3Bucket,
            's3AccessKey' => $s3Creds['access'],
            's3SecretKey' => $s3Creds['secret'],
            // The blade reads this to set REGISTRATION_ENABLED. It was never
            // passed, so it always fell back to false — and since record:init
            // seeds no admin and Sendrec's users table has no role column,
            // that shipped an instance with zero accounts and no way to make
            // one. Open it for the first sign-up, then re-run without the flag.
            'allowRegistration' => (bool) $this->option('allow-registration'),
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-record-sendrec.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Sendrec manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Sendrec...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/record-sendrec -n {$ns} --timeout=180s",
            190,
        ));

        $this->registerTool($kubectl, ClusterTool::RECORD, ['host' => $host]);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Sendrec async video platform stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Database:</>    <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveRecordHost(string $env): string
    {
        $service = SharedClusterService::RECORD;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveRecordHostReadOnly('local', null);
        }

        return $this->promptForCloudRecordHost($service, $env);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::RECORD);
    }

    protected function promptForCloudRecordHost(SharedClusterService $service, string $env): string
    {
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $existing = $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
        if ($existing) {
            return $existing;
        }

        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        $default = ($config && $webHost) ? $config->getSharedServiceHost($service, $env) : '';

        $host = text(
            label: "What host should {$service->label()} use in '{$env}'?",
            placeholder: $default !== '' ? $default : 'e.g. record.example.com',
            default: $default,
            required: true,
        );

        if ($config) {
            $config->setHost($env, $service->value, $host);
            $config->saveToFile($projectPath);
            $this->laraKubeInfo("Saved {$service->label()} host for '{$env}' to .larakube.json");
        }

        return $host;
    }
}
