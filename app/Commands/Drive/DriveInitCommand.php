<?php

namespace App\Commands\Drive;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsClusterSecrets;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class DriveInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, ReadsClusterSecrets, ResolvesToolEnvironment, StreamsProcessOutput, SyncsClusterSecrets;

    protected $signature = 'drive:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Drive (example.com → prefix.example.com)}
        {--no-plex   : Bypass Plex Commons (SQLite/Local PVC instead of Postgres/S3)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--engine=   : The drive engine to deploy ("ocis")}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the oCIS cloud storage and sync stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployDrive();
    }

    protected function deployDrive(): int
    {
        $this->resolveEngine();
        $env = $this->resolveEnvironment();
        $host = $this->resolveDriveHost($env);

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $this->plexContext = $context;
        $kubectl = $this->driveKubectl($context);
        $ns = $this->driveNamespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::DRIVE, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $s3Creds = null;

        if (! $noPlex) {
            if (! $this->ensureCommons(['seaweedfs'])) {
                return 1;
            }

            $s3Creds = $this->readCommonsS3Credentials();
            if (! $s3Creds) {
                $this->laraKubeError('Failed to read SeaweedFS credentials from the Plex Commons.');

                return 1;
            }

            if (! $this->allocateStorageBucket(StorageDriver::SEAWEEDFS, 'drive-ocis')) {
                return 1;
            }
        }

        $adminPassword = $this->readDriveSecret($kubectl, $ns, 'admin-password') ?? Str::random(24);
        $machineAuth = $this->readDriveSecret($kubectl, $ns, 'machine-auth-api-key') ?? Str::random(32);
        $jwtSecret = $this->readDriveSecret($kubectl, $ns, 'jwt-secret') ?? Str::random(32);
        $transferSecret = $this->readDriveSecret($kubectl, $ns, 'transfer-secret') ?? Str::random(32);
        $systemUserApiKey = $this->readDriveSecret($kubectl, $ns, 'system-user-api-key') ?? Str::random(32);
        $serviceAccountSecret = $this->readDriveSecret($kubectl, $ns, 'service-account-secret') ?? Str::random(32);
        $rekeyKey = $this->readDriveSecret($kubectl, $ns, 'rekey-key') ?? Str::random(32);

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $clusterEnv = $env === 'local' ? 'dev' : $env;
        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $clusterEnv, $adminPassword, $machineAuth, $jwtSecret, $transferSecret, $systemUserApiKey, $serviceAccountSecret, $rekeyKey) {
            $cmd = "{$kubectl} create secret generic drive-secrets -n {$ns} "
                .'--from-literal=admin-password='.escapeshellarg($adminPassword).' '
                .'--from-literal=machine-auth-api-key='.escapeshellarg($machineAuth).' '
                .'--from-literal=jwt-secret='.escapeshellarg($jwtSecret).' '
                .'--from-literal=transfer-secret='.escapeshellarg($transferSecret).' '
                .'--from-literal=system-user-api-key='.escapeshellarg($systemUserApiKey).' '
                .'--from-literal=service-account-secret='.escapeshellarg($serviceAccountSecret).' '
                .'--from-literal=rekey-key='.escapeshellarg($rekeyKey).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);

            if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
                $this->pushClusterSecret($kubectl, 'DRIVE_ADMIN_PASSWORD', $adminPassword, $clusterEnv);
                $this->pushClusterSecret($kubectl, 'DRIVE_MACHINE_AUTH_API_KEY', $machineAuth, $clusterEnv);
                $this->pushClusterSecret($kubectl, 'DRIVE_JWT_SECRET', $jwtSecret, $clusterEnv);
                $this->pushClusterSecret($kubectl, 'DRIVE_TRANSFER_SECRET', $transferSecret, $clusterEnv);
                $this->pushClusterSecret($kubectl, 'DRIVE_SYSTEM_USER_API_KEY', $systemUserApiKey, $clusterEnv);
                $this->pushClusterSecret($kubectl, 'DRIVE_SERVICE_ACCOUNT_SECRET', $serviceAccountSecret, $clusterEnv);
                $this->pushClusterSecret($kubectl, 'DRIVE_REKEY_KEY', $rekeyKey, $clusterEnv);
            }
        });

        $manifest = view('k8s.drive.ocis', [
            'host' => $host,
            's3Creds' => $s3Creds,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-drive.yaml';
        file_put_contents($tmp, $manifest);

        $engineName = 'oCIS';
        $deployName = 'deploy/drive-ocis';

        $this->withSpin("Applying Drive ({$engineName}) manifests...", fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin("Waiting for Drive ({$engineName})...", fn () => $this->runStreaming(
            "{$kubectl} rollout status {$deployName} -n {$ns} --timeout=120s",
            130,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Drive ({$engineName}) stack is live.");
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Admin Username:</>          <fg=blue>admin</>');
        $this->line("  <fg=gray>Admin Password:</>          <fg=blue>{$adminPassword}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveDriveHost(string $env): string
    {
        $service = SharedClusterService::DRIVE;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return $service->hostFor('kube');
        }

        return $this->promptForCloudDriveHost($service, $env);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::DRIVE);
    }

    protected function promptForCloudDriveHost(SharedClusterService $service, string $env): string
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
            placeholder: $default !== '' ? $default : 'e.g. drive.example.com',
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

    protected function resolveEngine(): string
    {
        $explicit = strtolower((string) $this->option('engine'));
        if ($explicit !== '' && $explicit !== 'ocis') {
            $this->laraKubeError("Unknown drive engine '{$explicit}'. Supported: ocis.");
            exit(1);
        }

        return 'ocis';
    }

    protected function driveKubectl(?string $context): string
    {
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== null && $context !== ''
            ? $kubectl.' --context '.escapeshellarg($context)
            : $kubectl;
    }

    protected function driveNamespace(): string
    {
        return 'larakube-shared';
    }

    protected function readDriveSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'drive-secrets', $key);
    }
}
