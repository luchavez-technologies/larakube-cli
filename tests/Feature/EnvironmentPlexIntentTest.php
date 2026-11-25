<?php

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Services\Project\BackingServices;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReconcilesPendingPlexCommons;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function plexIntentProject(
    string $dir,
    array $environments = [
        'local' => ['plex' => ['postgres', 'redis', 'seaweedfs'], 'managed' => ['postgres', 'redis', 'seaweedfs']],
        'staging' => ['ingress' => 'traefik', 'hosts' => ['web' => 'staging.example.com'], 'cloud' => ['context' => 'larakube-203.0.113.21', 'ip' => '203.0.113.21']],
    ],
): ConfigData {
    file_put_contents($dir.'/.env', "APP_NAME=plex-intent\n");
    file_put_contents($dir.'/.env.staging', "APP_NAME=plex-intent\nDB_HOST=postgres\nREDIS_HOST=redis\nAWS_ENDPOINT=http://seaweedfs:8333\n");

    $config = ConfigData::from([
        'name' => 'plex-intent',
        'framework' => 'laravel',
        'serverVariation' => 'frankenphp',
        'phpVersion' => '8.5',
        'database' => 'postgres',
        'cacheDriver' => 'redis',
        'objectStorage' => 'seaweedfs',
        'environments' => $environments,
    ]);
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->setCacheDriver(CacheDriver::REDIS);
    $config->setObjectStorage(StorageDriver::SEAWEEDFS);
    $config->setPath($dir);
    $config->saveToFile($dir);

    return $config;
}

beforeEach(function (): void {
    $this->temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $this->tempDir = $this->temporaryDirectory->path();
    $this->originalDir = getcwd();
    chdir($this->tempDir);

    Prompt::interactive(false);
});

afterEach(function (): void {
    chdir($this->originalDir);
    $this->temporaryDirectory->delete();
    gc_collect_cycles();
});

test('cloud:configure --only=plex saves Plex intent and strips self-hosted pods and PVCs from the overlay', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    plexIntentProject($this->tempDir);

    // Seed initial standalone manifests so we also verify stale PVCs are pruned.
    $this->artisan('heal', ['--force' => true])->assertExitCode(0);
    expect(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/postgres-volumes.yaml'))->toBeTrue()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/seaweedfs-volumes.yaml'))->toBeTrue();

    $this->artisan('cloud:configure', [
        'environment' => 'staging',
        '--only' => 'plex',
        '--plex' => 'postgres,redis,seaweedfs',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Pending Plex Commons services (postgres, redis, seaweedfs) will be provisioned automatically')
        ->assertExitCode(0);

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    $staging = $reloaded->getEnvironment('staging');

    expect($staging?->plex)->toBe(['postgres', 'redis', 'seaweedfs'])
        ->and($staging?->managed)->toBe([])
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/postgres-managed-delete-deployment.yaml'))->toBeTrue()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/redis-managed-delete-deployment.yaml'))->toBeTrue()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/seaweedfs-managed-delete-deployment.yaml'))->toBeTrue()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/postgres-volumes.yaml'))->toBeFalse()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/seaweedfs-volumes.yaml'))->toBeFalse();

    $deploymentPatch = (string) file_get_contents($this->tempDir.'/.infrastructure/k8s/overlays/staging/deployment-patch.yaml');
    expect($deploymentPatch)->not->toContain('nc -z -v -w 1 postgres 5432')
        ->and($deploymentPatch)->not->toContain('nc -z -v -w 1 redis 6379');
});

test('cloud:configure --only=plex removes unselected previously-joined services from plex and managed while keeping unrelated managed entries', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    plexIntentProject($this->tempDir, [
        'local' => [],
        'staging' => [
            'ingress' => 'traefik',
            'hosts' => ['web' => 'staging.example.com'],
            'plex' => ['postgres', 'redis'],
            'managed' => ['postgres', 'redis', 'seaweedfs'],
        ],
    ]);

    $this->artisan('heal', ['--force' => true])->assertExitCode(0);

    $this->artisan('cloud:configure', [
        'environment' => 'staging',
        '--only' => 'plex',
        '--plex' => 'postgres',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Self-hosted pods will return on next deploy for: redis.')
        ->assertExitCode(0);

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    $staging = $reloaded->getEnvironment('staging');

    // seaweedfs was in managed but NOT in previousPlex (external cloud-managed), so it stays managed.
    expect($staging?->plex)->toBe(['postgres'])
        ->and($staging?->managed)->toBe(['postgres', 'seaweedfs'])
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/postgres-managed-delete-deployment.yaml'))->toBeTrue()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/redis-managed-delete-deployment.yaml'))->toBeFalse()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/seaweedfs-managed-delete-deployment.yaml'))->toBeTrue();
});

test('cloud:configure --only=plex rejects unknown or ineligible service slugs', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    plexIntentProject($this->tempDir);

    $this->artisan('cloud:configure', [
        'environment' => 'staging',
        '--only' => 'plex',
        '--plex' => 'bogus',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain("Invalid --plex service(s): 'bogus'")
        ->assertExitCode(1);
});

test('cloud:configure --only=target --plex=... creates a new environment with Plex-backed manifests from the start', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    plexIntentProject($this->tempDir, ['local' => []]);

    $this->artisan('cloud:configure', [
        'environment' => 'staging',
        '--only' => 'target',
        '--context' => 'larakube-203.0.113.21',
        '--web-host' => 'staging.example.com',
        '--web-hosts' => '',
        '--ingress' => 'traefik',
        '--managed' => '',
        '--plex' => 'postgres,redis',
        '--ssh-key' => '~/.ssh/id_rsa',
        '--no-interaction' => true,
    ])
        ->expectsQuestion('SSH user', 'larakube')
        ->expectsQuestion('SSH port', '22')
        ->assertExitCode(0);

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    $staging = $reloaded->getEnvironment('staging');

    expect($staging?->plex)->toBe(['postgres', 'redis'])
        ->and($staging?->managed)->toBe([])
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/postgres-managed-delete-deployment.yaml'))->toBeTrue()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/redis-managed-delete-deployment.yaml'))->toBeTrue()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/seaweedfs-volumes.yaml'))->toBeTrue();
});

test('env command accepts --plex= and generates Plex-backed overlays', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    plexIntentProject($this->tempDir, ['local' => []]);

    $this->artisan('env', [
        'name' => 'staging',
        '--context' => 'larakube-203.0.113.21',
        '--web-host' => 'staging.example.com',
        '--ingress' => 'traefik',
        '--managed' => '',
        '--plex' => 'postgres,seaweedfs',
        '--web-hosts' => '',
        '--ssh-key' => '~/.ssh/id_rsa',
        '--ssh-user' => 'larakube',
        '--ssh-port' => '22',
        '--no-interaction' => true,
    ])
        ->expectsQuestion('Custom host for SeaweedFS S3 API in staging (optional)', '')
        ->expectsConfirmation('Configure a container registry for staging?', 'no')
        ->expectsConfirmation("Set up the CI/CD deploy workflow for 'staging' now?", 'no')
        ->assertExitCode(0);

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    expect($reloaded->getPlex('staging'))->toBe(['postgres', 'seaweedfs'])
        ->and($reloaded->getManaged('staging'))->toBeEmpty()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/postgres-managed-delete-deployment.yaml'))->toBeTrue()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/seaweedfs-managed-delete-deployment.yaml'))->toBeTrue()
        ->and(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/staging/redis-managed-delete-deployment.yaml'))->toBeFalse();
});

test('ReconcilesPendingPlexCommons invokes plex:join for pending services and fails loudly when any stay unjoined', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    $config = plexIntentProject($this->tempDir, [
        'local' => [],
        'staging' => [
            'ingress' => 'traefik',
            'hosts' => ['web' => 'staging.example.com'],
            'plex' => ['postgres', 'redis'],
            'managed' => [],
        ],
    ]);

    $calls = [];
    $runner = new class($calls)
    {
        use InteractsWithProjectConfig, LaraKubeOutput, ReconcilesPendingPlexCommons;

        public function __construct(public array &$calls) {}

        public function call($command, array $arguments = []): int
        {
            $this->calls[] = ['command' => $command, 'arguments' => $arguments];

            // Simulate plex:join succeeding for postgres only while redis stayed pending.
            $cfg = ConfigData::loadFromFile(getcwd());
            $data = $cfg->toArray();
            $data['environments']['staging']['managed'] = ['postgres'];
            ConfigData::from($data)->saveToFile(getcwd());

            return 0;
        }

        public function reconcile(ConfigData &$config, string $env, ?string $context, string $projectPath): bool
        {
            return $this->reconcilePendingPlexCommons($config, $env, $context, $projectPath);
        }

        public function laraKubeInfo(string $message): void {}

        public function laraKubeError(string $message): void {}

        public function line($string, $style = null, $verbosity = null): void {}
    };

    $ok = $runner->reconcile($config, 'staging', 'larakube-203.0.113.21', $this->tempDir);

    expect($ok)->toBeFalse()
        ->and($calls)->toHaveCount(1)
        ->and($calls[0]['command'])->toBe('plex:join')
        ->and($calls[0]['arguments'])->toBe([
            'environment' => 'staging',
            '--service' => ['postgres', 'redis'],
            '--no-interaction' => true,
            '--context' => 'larakube-203.0.113.21',
        ]);
});

test('cloud:deploy reconciles pending Plex services on the cluster before building and fails loudly if unjoined', function (): void {
    plexIntentProject($this->tempDir, [
        'local' => [],
        'staging' => [
            'ingress' => 'traefik',
            'hosts' => ['web' => 'staging.example.com', 's3' => 's3-staging.example.com'],
            'plex' => ['postgres'],
            'managed' => [],
            'cloud' => ['context' => 'larakube-203.0.113.21', 'ip' => '203.0.113.21'],
        ],
    ]);

    // Existing self-hosted PVC triggers plex:join's data-safety guard (exits 0
    // after dropping postgres in non-interactive mode without joining it),
    // which cloud:deploy's reconciler must detect and fail loudly on.
    Process::fake([
        '*get pvc*' => Process::result(output: 'persistentvolumeclaim/plex-intent-postgres-pvc'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('cloud:deploy', [
        'environment' => 'staging',
        '--no-interaction' => true,
    ])
        ->expectsConfirmation("Keep the web host 'staging.example.com' for 'staging'?", 'yes')
        ->expectsConfirmation("Keep the SeaweedFS S3 API host 's3-staging.example.com' for 'staging'?", 'yes')
        ->expectsOutputToContain("Provisioning Plex Commons services for 'staging': postgres")
        ->expectsOutputToContain("Plex Commons service(s) still pending for 'staging': postgres.")
        ->expectsOutputToContain('larakube plex:migrate staging')
        ->assertExitCode(1);
});

test('cloud:configure --only=ci reconciles pending Plex services before uploading secrets or writing the workflow', function (): void {
    plexIntentProject($this->tempDir, [
        'local' => [],
        'staging' => [
            'ingress' => 'traefik',
            'hosts' => ['web' => 'staging.example.com', 's3' => 's3-staging.example.com'],
            'plex' => ['postgres'],
            'managed' => [],
            'cloud' => ['context' => 'larakube-203.0.113.21', 'ip' => '203.0.113.21'],
        ],
    ]);

    Process::fake([
        '*git remote get-url*' => Process::result(output: 'git@github.com:acme/plex-intent.git'),
        '*command -v gh*' => Process::result(output: '/usr/local/bin/gh'),
        '*get pvc*' => Process::result(output: 'persistentvolumeclaim/plex-intent-postgres-pvc'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('cloud:configure', [
        'environment' => 'staging',
        '--only' => 'ci',
        '--web-host' => 'staging.example.com',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain("Provisioning Plex Commons services for 'staging': postgres")
        ->expectsOutputToContain("Plex Commons service(s) still pending for 'staging': postgres.")
        ->assertExitCode(1);
});

test('BackingServices marks services in plex but not managed as pending', function (): void {
    $config = plexIntentProject($this->tempDir, [
        'local' => [],
        'staging' => [
            'plex' => ['postgres', 'redis'],
            'managed' => ['postgres'],
        ],
    ]);

    $services = collect((new BackingServices)->describe($config, 'staging', [
        'DB_HOST' => 'postgres.larakube-plex.svc.cluster.local',
        'REDIS_HOST' => 'redis.larakube-plex.svc.cluster.local',
    ]))->keyBy('kind');

    expect($services['database']['mode'])->toBe('commons')
        ->and($services['database']['pending'])->toBeFalse()
        ->and($services['cache']['mode'])->toBe('commons')
        ->and($services['cache']['pending'])->toBeTrue()
        ->and($services['storage']['mode'])->toBe('pod')
        ->and($services['storage']['pending'])->toBeFalse();
});

test('buildPublicEnvScript includes non-secret Plex connection vars from .env.<env> and excludes all secrets and keys', function (): void {
    $config = plexIntentProject($this->tempDir, [
        'local' => [],
        'staging' => [
            'ingress' => 'traefik',
            'hosts' => ['web' => 'staging.example.com'],
            'plex' => ['postgres', 'redis', 'seaweedfs'],
            'managed' => ['postgres', 'redis', 'seaweedfs'],
        ],
    ]);

    file_put_contents($this->tempDir.'/.env.staging', implode("\n", [
        'APP_NAME=plex-intent',
        'DB_CONNECTION=pgsql',
        'DB_HOST=postgres.larakube-plex.svc.cluster.local',
        'DB_PORT=5432',
        'DB_DATABASE=plex_intent_staging',
        'DB_USERNAME=plex_intent_staging',
        'DB_PASSWORD=super-secret-db-password',
        'REDIS_HOST=redis.larakube-plex.svc.cluster.local',
        'REDIS_PORT=6379',
        'REDIS_DB=4',
        'REDIS_PASSWORD=super-secret-redis-password',
        'AWS_ACCESS_KEY_ID=larakube-access',
        'AWS_SECRET_ACCESS_KEY=super-secret-s3-key',
        'AWS_BUCKET=plex-intent-staging',
        'AWS_ENDPOINT=http://seaweedfs.larakube-plex.svc.cluster.local:8333',
        'AWS_URL=https://s3-staging.example.com/plex-intent-staging',
    ])."\n");

    $runner = new class
    {
        use App\Traits\ConfiguresCloudEnvironment;
        use App\Traits\GeneratesProjectInfrastructure;
        use App\Traits\InteractsWithEnvironments;
        use InteractsWithProjectConfig;
        use LaraKubeOutput;

        public function script(ConfigData $config, string $env): string
        {
            return $this->buildPublicEnvScript($config, $env);
        }
    };

    $script = $runner->script($config, 'staging');

    expect($script)
        ->toContain("echo 'DB_CONNECTION=pgsql' >> .env")
        ->toContain("echo 'DB_HOST=postgres.larakube-plex.svc.cluster.local' >> .env")
        ->toContain("echo 'DB_DATABASE=plex_intent_staging' >> .env")
        ->toContain("echo 'DB_USERNAME=plex_intent_staging' >> .env")
        ->toContain("echo 'REDIS_HOST=redis.larakube-plex.svc.cluster.local' >> .env")
        ->toContain("echo 'REDIS_DB=4' >> .env")
        ->toContain("echo 'AWS_BUCKET=plex-intent-staging' >> .env")
        ->toContain("echo 'AWS_ENDPOINT=http://seaweedfs.larakube-plex.svc.cluster.local:8333' >> .env")
        ->not->toContain('DB_PASSWORD')
        ->not->toContain('REDIS_PASSWORD')
        ->not->toContain('AWS_ACCESS_KEY_ID')
        ->not->toContain('AWS_SECRET_ACCESS_KEY')
        ->not->toContain('super-secret');
});

test('cloud:configure --only=hosts takes client-facing hosts from --host without prompting', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    $config = plexIntentProject($this->tempDir);
    $config->addFeature(App\Enums\LaravelFeature::REVERB);
    $config->saveToFile($this->tempDir);

    $this->artisan('cloud:configure', [
        'environment' => 'staging',
        '--only' => 'hosts',
        '--web-host' => 'staging.example.com',
        '--web-hosts' => '',
        '--host' => ['reverb=ws.example.org', 's3=s3.example.org'],
        '--no-interaction' => true,
    ])->assertExitCode(0);

    $saved = ConfigData::loadFromFile($this->tempDir);
    expect($saved->getHost('staging', 'reverb'))->toBe('ws.example.org')
        ->and($saved->getHost('staging', 's3'))->toBe('s3.example.org');
});

test('cloud:configure --only=hosts rejects a --host for a service the environment does not have', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    plexIntentProject($this->tempDir);

    $this->artisan('cloud:configure', [
        'environment' => 'staging',
        '--only' => 'hosts',
        '--web-host' => 'staging.example.com',
        '--web-hosts' => '',
        '--host' => ['bogus=x.example.org'],
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain("Unknown --host service(s): 'bogus'")
        ->assertExitCode(1);
});

test('cloud:configure --only=target rejects a service that is both --managed and --plex', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    plexIntentProject($this->tempDir, ['local' => []]);

    $this->artisan('cloud:configure', [
        'environment' => 'staging',
        '--only' => 'target',
        '--context' => 'larakube-203.0.113.21',
        '--web-host' => 'staging.example.com',
        '--web-hosts' => '',
        '--ingress' => 'traefik',
        '--managed' => 'postgres',
        '--plex' => 'postgres,redis',
        '--ssh-key' => '~/.ssh/id_rsa',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain("Service(s) 'postgres' can't be both externally managed")
        ->assertExitCode(1);
});
