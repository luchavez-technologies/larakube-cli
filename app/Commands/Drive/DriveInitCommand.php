<?php

namespace App\Commands\Drive;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class DriveInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithPlex, LaraKubeOutput, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'drive:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Drive (example.com → prefix.example.com)}
        {--no-plex   : Bypass Plex Commons (SQLite/Local PVC instead of Postgres/S3)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--engine=   : The drive engine to deploy ("ocis" or "nextcloud")}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Deploy a cloud storage and sync stack (oCIS or Nextcloud) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployDrive();
    }

    protected function deployDrive(): int
    {
        $engine = $this->resolveEngine();
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

        $dbPassword = null;
        $redisIndex = null;
        $s3Creds = null;

        if (! $noPlex) {
            if ($engine === 'nextcloud') {
                if (! $this->ensureCommons(['postgres', 'redis'])) {
                    return 1;
                }

                $dbPassword = $this->readDriveSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
                if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, 'drive', $dbPassword)) {
                    return 1;
                }

                $redisIndex = $this->allocateCommonsRedisIndex('drive');
                if ($redisIndex === null) {
                    $this->laraKubeError('The Commons Redis is full (all 16 logical databases taken). Cannot allocate an index for Drive.');

                    return 1;
                }
            } else {
                // oCIS
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
        }

        $adminPassword = $this->readDriveSecret($kubectl, $ns, 'admin-password') ?? Str::random(24);
        $machineAuth = $this->readDriveSecret($kubectl, $ns, 'machine-auth-api-key') ?? Str::random(32);

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $adminPassword, $dbPassword, $machineAuth) {
            $cmd = "{$kubectl} create secret generic drive-secrets -n {$ns} "
                .'--from-literal=admin-password='.escapeshellarg($adminPassword).' '
                .'--from-literal=machine-auth-api-key='.escapeshellarg($machineAuth).' ';

            if ($dbPassword) {
                $cmd .= '--from-literal=db-password='.escapeshellarg($dbPassword).' ';
            }

            $cmd .= "--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);
        });

        $manifest = view("k8s.drive.{$engine}", [
            'engine' => $engine,
            'host' => $host,
            'dbPassword' => $dbPassword,
            'redisIndex' => $redisIndex,
            's3Creds' => $s3Creds,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-drive.yaml';
        file_put_contents($tmp, $manifest);

        $engineName = $engine === 'nextcloud' ? 'Nextcloud' : 'oCIS';
        $deployName = $engine === 'nextcloud' ? 'deploy/drive-nextcloud' : 'deploy/drive-ocis';

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
        if (in_array($explicit, ['ocis', 'nextcloud'], true)) {
            return $explicit;
        }

        return select(
            label: 'Which drive engine do you want to deploy?',
            options: [
                'ocis' => 'oCIS (Go-based, ultra-fast, native S3 backend)',
                'nextcloud' => 'Nextcloud (PHP-based, massive all-in-one suite)',
            ],
            default: 'ocis',
        );
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
        $val = trim(Process::run("{$kubectl} get secret drive-secrets -n {$ns} -o jsonpath='{.data.{$key}}' 2>/dev/null")->output());

        return $val === '' ? null : (string) base64_decode($val);
    }
}
