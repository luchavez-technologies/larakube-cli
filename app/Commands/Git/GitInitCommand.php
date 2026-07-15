<?php

namespace App\Commands\Git;

use App\Data\ConfigData;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithGitForge;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class GitInitCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithGitForge, InteractsWithPlex, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'git:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the Gitea host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--env=      : Legacy alias for the environment argument}
        {--domain=   : Raw override for the Gitea cluster domain (e.g. example.com → git.example.com); skips the prompt}
        {--no-plex   : Bypass Plex Commons and use local PVC storage instead}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--remove    : Tear down the Gitea stack from larakube-shared}';

    protected $description = 'Deploy the cluster-wide Gitea forge, CI/CD runner, and package registry';

    public function handle(): int
    {
        $this->renderHeader();

        // Scope the Plex trait context to match the passed option
        $this->plexContext = $this->option('context');

        return $this->option('remove')
            ? $this->removeGit()
            : $this->deployGit();
    }

    protected function deployGit(): int
    {
        $kubectl = $this->gitKubectl($this->option('context'));
        $ns = $this->gitNamespace();
        $noPlex = (bool) $this->option('no-plex');

        $s3Service = null;
        $s3Endpoint = '';
        $s3AccessKey = '';
        $s3SecretKey = '';

        if (! $noPlex) {
            $spec = $this->getCommonsSpec();
            if ($spec !== null) {
                $enabled = $this->enabledCommonsServices($spec);
                if (in_array('seaweedfs', $enabled, true)) {
                    $s3Service = 'seaweedfs';
                } elseif (in_array('minio', $enabled, true)) {
                    $s3Service = 'minio';
                }
            }

            if ($s3Service === null) {
                // Default to SeaweedFS (Apache-licensed)
                if (! $this->ensureCommons(['seaweedfs'])) {
                    return 1;
                }
                $s3Service = 'seaweedfs';
            } else {
                if (! $this->ensureCommons([$s3Service])) {
                    return 1;
                }
            }

            // Read credentials and endpoints
            $creds = $this->readCommonsS3Credentials();
            if ($creds === null) {
                $this->laraKubeError('Commons S3 credentials not found. Re-run `larakube plex:init`.');

                return 1;
            }

            $s3AccessKey = $creds['access'];
            $s3SecretKey = $creds['secret'];
            $driver = StorageDriver::from($s3Service);
            $s3Endpoint = "http://{$s3Service}.{$this->plexNamespace()}.svc.cluster.local:{$driver->port()}";

            // Allocate S3 buckets
            if (! $this->allocateStorageBucket($driver, 'gitea-storage') ||
                ! $this->allocateStorageBucket($driver, 'gitea-packages') ||
                ! $this->allocateStorageBucket($driver, 'gitea-lfs')) {
                return 1;
            }
        }

        $host = $this->resolveGitHost();

        // Read or generate password & secrets
        $adminPassword = $this->readExistingAdminPassword($kubectl, $ns);
        if ($adminPassword === null) {
            $adminPassword = Str::random(16);
        }

        $secretKey = Str::random(16);
        $internalToken = Str::random(16);
        $jwtSecret = Str::random(32);

        // Ensure namespace exists
        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $env = $this->option('env') ?: $this->argument('environment');
        if (!$env && !$this->option('domain')) {
            $env = 'local';
        }

        $vpnOnly = (bool) $this->option('vpn-only');

        // 1. Initial deployment with Gitea Core only (runner token placeholder)
        $manifest = view('k8s.gitea.shared', [
            'host' => $host,
            'adminPassword' => $adminPassword,
            'registryToken' => 'pending',
            'runnerToken' => 'pending',
            'secretKey' => $secretKey,
            'internalToken' => $internalToken,
            'jwtSecret' => $jwtSecret,
            'noPlex' => $noPlex,
            's3Endpoint' => $s3Endpoint,
            's3AccessKey' => $s3AccessKey,
            's3SecretKey' => $s3SecretKey,
            'isLocal' => $env === 'local',
            'vpnOnly' => $vpnOnly,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-gitea.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Gitea core manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Gitea rollout...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/gitea -n {$ns} --timeout=120s",
            130,
        ));

        // 2. CLI commands inside pod to create user and get tokens
        $registryToken = null;
        $runnerToken = null;

        $this->withSpin('Initializing Gitea admin user...', function () use ($kubectl, $ns, $adminPassword) {
            $list = Process::run("{$kubectl} exec deploy/gitea -n {$ns} -- gitea --config /data/gitea/conf/app.ini admin user list")->output();
            if (str_contains($list, 'larakube')) {
                return true;
            }

            return Process::run(
                "{$kubectl} exec deploy/gitea -n {$ns} -- ".
                'gitea --config /data/gitea/conf/app.ini admin user create '.
                '--username larakube --password '.escapeshellarg($adminPassword).' '.
                '--email admin@larakube.local --admin',
            )->successful();
        });

        $this->withSpin('Generating Gitea package registry token...', function () use ($kubectl, $ns, &$registryToken) {
            $cmd = "{$kubectl} exec deploy/gitea -n {$ns} -- ".
                'gitea --config /data/gitea/conf/app.ini admin user generate-access-token '.
                '--username larakube --token-name larakube-registry --scopes write:package,read:package --raw';

            $result = Process::run($cmd);
            if ($result->successful()) {
                $registryToken = trim($result->output());

                return true;
            }

            return false;
        });

        $this->withSpin('Generating Gitea Actions runner token...', function () use ($kubectl, $ns, &$runnerToken) {
            $cmd = "{$kubectl} exec deploy/gitea -n {$ns} -- ".
                'gitea --config /data/gitea/conf/app.ini actions generate-runner-token';

            $result = Process::run($cmd);
            if ($result->successful()) {
                $runnerToken = trim($result->output());

                return true;
            }

            return false;
        });

        // 3. Re-apply final configuration containing real tokens
        $manifestFinal = view('k8s.gitea.shared', [
            'host' => $host,
            'adminPassword' => $adminPassword,
            'registryToken' => $registryToken ?? 'pending',
            'runnerToken' => $runnerToken ?? 'pending',
            'secretKey' => $secretKey,
            'internalToken' => $internalToken,
            'jwtSecret' => $jwtSecret,
            'noPlex' => $noPlex,
            's3Endpoint' => $s3Endpoint,
            's3AccessKey' => $s3AccessKey,
            's3SecretKey' => $s3SecretKey,
            'isLocal' => $env === 'local',
            'vpnOnly' => $vpnOnly,
        ])->render();

        $tmpFinal = sys_get_temp_dir().'/larakube-gitea-final.yaml';
        file_put_contents($tmpFinal, $manifestFinal);

        $this->withSpin('Applying Gitea Actions runner...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmpFinal}"));
        @unlink($tmpFinal);

        if ($runnerToken !== null) {
            $this->withSpin('Waiting for Actions Runner...', fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/gitea-runner -n {$ns} --timeout=120s",
                130,
            ));
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Gitea forge and Actions runner are live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Admin Email:</>             admin@larakube.local');
        $this->line('  <fg=gray>Admin Username:</>          larakube');
        $this->line("  <fg=gray>Admin Password:</>          {$adminPassword}");
        $this->newLine();

        return 0;
    }

    protected function removeGit(): int
    {
        $kubectl = $this->gitKubectl($this->option('context'));
        $ns = $this->gitNamespace();

        $this->withSpin('Removing Gitea resources...', fn () => Process::run(
            "{$kubectl} delete deploy/gitea deploy/gitea-runner svc/gitea-http svc/gitea-ssh ingress/gitea secret/gitea-admin pvc/gitea-data -n {$ns} --ignore-not-found",
        ));

        $this->laraKubeInfo('Gitea removed from larakube-shared.');

        return 0;
    }

    /** Parse admin password from existing secret */
    protected function readExistingAdminPassword(string $kubectl, string $ns): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret gitea-admin -n {$ns} -o jsonpath='{.data.password}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    /** Resolve the Gitea host for this environment */
    protected function resolveGitHost(): string
    {
        $service = SharedClusterService::GITEA;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        $env = $this->resolveEnvironment();

        if ($env === 'local') {
            return (string) $this->resolveGitHostReadOnly('local', null);
        }

        return $this->promptForCloudGitHost($service, $env);
    }

    /** Decide which environment this install targets */
    protected function resolveEnvironment(): string
    {
        $explicit = (string) ($this->argument('environment') ?: $this->option('env') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        if ($this->option('no-interaction') || $this->option('domain')) {
            return 'local';
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $envs = $config ? array_merge(['local'], $config->getCloudEnvironments()) : ['local'];

        return select(
            label: 'Which environment is this Gitea install for?',
            options: array_combine($envs, $envs),
            default: 'local',
            hint: 'Local uses your dev TLD; a cloud env asks for + persists the Gitea host.',
        );
    }

    /** Prompt for (and persist) Gitea host */
    protected function promptForCloudGitHost(SharedClusterService $service, string $env): string
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
            placeholder: $default !== '' ? $default : 'e.g. git.example.com',
            default: $default,
            required: true,
            hint: 'Point this DNS at the cluster and add TLS like any other ingress host.',
        );

        if ($config) {
            $config->setHost($env, $service->value, $host);
            $config->saveToFile($projectPath);
            $this->laraKubeInfo("Saved {$service->label()} host for '{$env}' to .larakube.json");
        }

        return $host;
    }
}
