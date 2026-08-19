<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Build a minimal ConfigData suitable for Plex auto-provisioning tests.
 */
function plexNewTestConfig(array $overrides = []): ConfigData
{
    $config = ConfigData::from(array_merge([
        'name' => 'test-app',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.4',
        'database' => 'postgres',
        'cacheDriver' => 'redis',
        'environments' => [
            'local' => [],
        ],
    ], $overrides));
    $config->resolveDependencies();

    return $config;
}

/**
 * Create an anonymous class that exposes the InteractsWithPlex trait methods
 * in a testable context (since traits can't be tested directly and the trait
 * requires a Command context for output helpers).
 */
function createPlexTestCommand(): object
{
    return new class extends Command
    {
        use InteractsWithPlex, LaraKubeOutput, StreamsProcessOutput;

        protected $signature = 'plex-test-dummy';

        public function handle(): int
        {
            return 0;
        }

        /**
         * Expose the protected plexKubectl for assertions.
         */
        public function getPlexKubectl(): string
        {
            return $this->plexKubectl();
        }

        /**
         * Expose ensurePlexProvisionedForApp for direct testing.
         */
        public function testEnsurePlexProvisionedForApp(ConfigData $config, string $env = 'local'): ?array
        {
            return $this->ensurePlexProvisionedForApp($config, $env);
        }

        /**
         * Override withSpin to just run the callback (no spinner in tests).
         */
        public function withSpin(string $message, callable $callback): mixed
        {
            return $callback();
        }
    };
}

// ---------------------------------------------------------------------------
// ensurePlexProvisionedForApp — skips for non-Plex databases
// ---------------------------------------------------------------------------

test('ensurePlexProvisionedForApp returns null for SQLite', function (): void {
    Process::fake();

    $config = plexNewTestConfig(['database' => 'sqlite']);
    $command = createPlexTestCommand();

    $result = $command->testEnsurePlexProvisionedForApp($config);

    expect($result)->toBeNull();

    // Should NOT even attempt to reach the cluster
    Process::assertNotRan(fn ($p) => str_contains($p->command, 'cluster-info'));
});

test('ensurePlexProvisionedForApp returns null when cluster is unreachable', function (): void {
    Process::fake([
        '*cluster-info*' => Process::result(exitCode: 1),
    ]);

    $config = plexNewTestConfig(['database' => 'postgres']);
    $command = createPlexTestCommand();

    $result = $command->testEnsurePlexProvisionedForApp($config);

    expect($result)->toBeNull();
});

test('ensurePlexProvisionedForApp auto-bootstraps Commons when missing and allocates tenant', function (): void {
    $commonsSpecJson = json_encode([
        'version' => 1,
        'services' => [
            'postgres' => ['enabled' => true, 'port' => 5432],
            'redis' => ['enabled' => true, 'port' => 6379],
        ],
    ]);

    // First call to getCommonsSpec returns null (Commons not initialized),
    // second call (after plex:init) returns the spec.
    $commonsSpecCallCount = 0;

    Process::fake([
        // Cluster reachability check
        '*cluster-info*' => Process::result(output: 'Kubernetes control plane is running'),
        // First getCommonsSpec call returns empty (no Commons)
        // After plex:init, second call returns the spec
        '*get configmap plex-commons*' => Process::result(
            output: $commonsSpecJson,
        ),
        // ensurePlexServiceRunning check
        '*get deployment/plex-postgres*' => Process::result(output: '1'),
        // rollout status
        '*rollout status*' => Process::result(output: 'deployment "postgres" successfully rolled out'),
        // allocateDatabase: kubectl exec to run SQL
        '*exec -i*' => Process::result(output: 'CREATE DATABASE'),
        // registerTenantDatabase: registry operations
        '*get configmap plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*apply -f*' => Process::result(output: 'applied'),
        // Fallback
        '*' => Process::result(output: ''),
    ]);

    $config = plexNewTestConfig(['database' => 'postgres']);
    $command = createPlexTestCommand();

    $result = $command->testEnsurePlexProvisionedForApp($config);

    expect($result)
        ->not->toBeNull()
        ->and($result['tenant'])->toBe('test_app')
        ->and($result['driver'])->toBe(DatabaseDriver::POSTGRESQL)
        ->and($result['host'])->toBe('postgres.larakube-plex.svc.cluster.local')
        ->and($result['port'])->toBe(5432)
        ->and($result['password'])->toBeString()->toHaveLength(32)
        ->and($result['services'])->toContain('postgres', 'redis');
});

test('ensurePlexProvisionedForApp works with MySQL driver', function (): void {
    $commonsSpecJson = json_encode([
        'version' => 1,
        'services' => [
            'mysql' => ['enabled' => true, 'port' => 3306],
            'redis' => ['enabled' => true, 'port' => 6379],
        ],
    ]);

    Process::fake([
        '*cluster-info*' => Process::result(output: 'OK'),
        '*get configmap plex-commons*' => Process::result(output: $commonsSpecJson),
        '*get deployment/plex-mysql*' => Process::result(output: '1'),
        '*rollout status*' => Process::result(output: 'OK'),
        '*exec -i*' => Process::result(output: 'OK'),
        '*get configmap plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*apply -f*' => Process::result(output: 'applied'),
        '*' => Process::result(output: ''),
    ]);

    $config = plexNewTestConfig(['database' => 'mysql']);
    $command = createPlexTestCommand();

    $result = $command->testEnsurePlexProvisionedForApp($config);

    expect($result)
        ->not->toBeNull()
        ->and($result['driver'])->toBe(DatabaseDriver::MYSQL)
        ->and($result['host'])->toBe('mysql.larakube-plex.svc.cluster.local')
        ->and($result['port'])->toBe(3306);
});

test('ensurePlexProvisionedForApp returns null when database service is not enabled in Commons', function (): void {
    // Commons only has Postgres enabled, but the app needs MySQL
    $commonsSpecJson = json_encode([
        'version' => 1,
        'services' => [
            'postgres' => ['enabled' => true, 'port' => 5432],
            'mysql' => ['enabled' => false, 'port' => 3306],
            'redis' => ['enabled' => true, 'port' => 6379],
        ],
    ]);

    Process::fake([
        '*cluster-info*' => Process::result(output: 'OK'),
        '*get configmap plex-commons*' => Process::result(output: $commonsSpecJson),
        '*' => Process::result(output: ''),
    ]);

    $config = plexNewTestConfig(['database' => 'mysql']);
    $command = createPlexTestCommand();

    $result = $command->testEnsurePlexProvisionedForApp($config);

    expect($result)->toBeNull();
});

test('ensurePlexProvisionedForApp catches exceptions and returns null', function (): void {
    Process::fake([
        '*cluster-info*' => Process::result(output: 'OK'),
        '*get configmap plex-commons*' => Process::result(exitCode: 1, errorOutput: 'connection refused'),
        '*' => Process::result(output: ''),
    ]);

    $config = plexNewTestConfig(['database' => 'postgres']);
    $command = createPlexTestCommand();

    // Should not throw — the method catches all exceptions
    $result = $command->testEnsurePlexProvisionedForApp($config);

    expect($result)->toBeNull();
});

test('ensurePlexProvisionedForApp uses production suffix for local env tenant identifier', function (): void {
    $commonsSpecJson = json_encode([
        'version' => 1,
        'services' => [
            'postgres' => ['enabled' => true, 'port' => 5432],
            'redis' => ['enabled' => true, 'port' => 6379],
        ],
    ]);

    Process::fake([
        '*cluster-info*' => Process::result(output: 'OK'),
        '*get configmap plex-commons*' => Process::result(output: $commonsSpecJson),
        '*get deployment/plex-postgres*' => Process::result(output: '1'),
        '*rollout status*' => Process::result(output: 'OK'),
        '*exec -i*' => Process::result(output: 'OK'),
        '*get configmap plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*apply -f*' => Process::result(output: 'applied'),
        '*' => Process::result(output: ''),
    ]);

    $config = plexNewTestConfig(['name' => 'my-cool-app', 'database' => 'postgres']);
    $command = createPlexTestCommand();

    $result = $command->testEnsurePlexProvisionedForApp($config, 'local');

    // For local env, tenant uses 'production' suffix convention → un-suffixed form
    expect($result['tenant'])->toBe('my_cool_app');
});

// ---------------------------------------------------------------------------
// NewCommand --no-plex flag registration
// ---------------------------------------------------------------------------

test('new command registers the --no-plex option', function (): void {
    $this->artisan('new --help')
        ->expectsOutputToContain('--no-plex');
});

test('statamic:new command registers the --no-plex option', function (): void {
    $this->artisan('statamic:new --help')
        ->expectsOutputToContain('--no-plex');
});

// ---------------------------------------------------------------------------
// Plex services include all project commons services
// ---------------------------------------------------------------------------

test('ensurePlexProvisionedForApp returns all project commons services', function (): void {
    $commonsSpecJson = json_encode([
        'version' => 1,
        'services' => [
            'postgres' => ['enabled' => true, 'port' => 5432],
            'redis' => ['enabled' => true, 'port' => 6379],
            'seaweedfs' => ['enabled' => true, 'port' => 8333],
        ],
    ]);

    Process::fake([
        '*cluster-info*' => Process::result(output: 'OK'),
        '*get configmap plex-commons*' => Process::result(output: $commonsSpecJson),
        '*get deployment/plex-postgres*' => Process::result(output: '1'),
        '*rollout status*' => Process::result(output: 'OK'),
        '*exec -i*' => Process::result(output: 'OK'),
        '*get configmap plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*apply -f*' => Process::result(output: 'applied'),
        '*' => Process::result(output: ''),
    ]);

    $config = plexNewTestConfig([
        'database' => 'postgres',
        'cache' => 'redis',
        'objectStorage' => 'seaweedfs',
    ]);
    $command = createPlexTestCommand();

    $result = $command->testEnsurePlexProvisionedForApp($config);

    expect($result['services'])
        ->toContain('postgres')
        ->toContain('redis')
        ->toContain('seaweedfs');
});
