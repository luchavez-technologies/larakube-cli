<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Data\GlobalConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\FrontendStack;
use App\Enums\IngressController;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PhpVersion;
use App\Enums\SearchDriver;
use App\Enums\ServerVariation;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('it casts strings to enums correctly', function (): void {
    $data = [
        'blueprints' => ['filament'],
        'serverVariation' => 'fpm-nginx',
        'frontend' => 'react',
        'phpVersion' => '8.5',
        'os' => 'alpine',
        'strategy' => 'single-node',
    ];

    $config = ConfigData::from($data);

    // Single Enums
    expect($config->serverVariation)->toEqual(ServerVariation::FPM_NGINX);
    expect($config->frontend)->toEqual(FrontendStack::REACT)
        ->and($config->phpVersion)->toEqual(PhpVersion::PHP_8_5)
        ->and($config->os)->toEqual(OperatingSystem::ALPINE)
        ->and($config->strategy)->toEqual(DeploymentStrategy::SINGLE_NODE);

    // Array of Enums
    expect($config->blueprints)->toBeArray();
    expect($config->blueprints[0])->toEqual(Blueprint::FILAMENT);
});

test('it handles multiple enum values in arrays', function (): void {
    $data = [
        'databases' => ['mysql', 'postgres'],
        'cacheDrivers' => ['redis'],
        'features' => ['horizon', 'reverb'],
    ];

    $config = ConfigData::from($data);

    expect($config->databases)->toHaveCount(2)
        ->toMatchArray([0 => DatabaseDriver::MYSQL, 1 => DatabaseDriver::POSTGRESQL])
        ->and($config->cacheDrivers)->toHaveCount(1)
        ->and($config->cacheDrivers[0])->toEqual(CacheDriver::REDIS)
        ->and($config->features)->toHaveCount(2)
        ->toMatchArray([0 => LaravelFeature::HORIZON, 1 => LaravelFeature::REVERB]);
});

test('it maintains default values', function (): void {
    $config = ConfigData::from([]);

    expect($config->strategy)->toEqual(DeploymentStrategy::SINGLE_NODE)
        ->and($config->getEnvironments())->toBe(['local', 'production'])
        ->and($config->getEnvironment('local'))->toBeInstanceOf(EnvironmentData::class)
        ->and($config->getEnvironment('production'))->toBeInstanceOf(EnvironmentData::class)
        ->and($config->getIngress('local'))->toEqual(IngressController::TRAEFIK)
        ->and($config->getIngress('production'))->toEqual(IngressController::TRAEFIK)
        ->and($config->githubActions)->toBeTrue()
        ->and($config->isSystem)->toBeFalse()
        ->and($config->isScaffolding)->toBeFalse();
});

test('environments are promoted from json array shape', function (): void {
    $config = ConfigData::from([
        'environments' => [
            'local' => ['managed' => [], 'hosts' => []],
            'production' => [
                'managed' => ['postgres', 'redis'],
                'hosts' => ['web' => 'example.com'],
            ],
        ],
    ]);

    expect($config->getEnvironment('production'))->toBeInstanceOf(EnvironmentData::class)
        ->and($config->getManaged('production'))->toBe(['postgres', 'redis'])
        ->and($config->getManaged('local'))->toBeEmpty()
        ->and($config->getEnvironment('production')->hosts['web'])->toBe('example.com')
        ->and($config->getAppUrl('production'))->toBe('https://example.com');
});

test('cloud envs deploy production safe app env and debug', function (): void {
    $config = ConfigData::from([
        'name' => 'demo',
        'environments' => [
            'local' => ['hosts' => []],
            'production' => ['hosts' => ['web' => 'example.com']],
            'staging' => ['hosts' => ['web' => 'staging.example.com']],
        ],
    ]);

    // Cloud envs report APP_ENV=production (hardcoded — Laravel keys its
    // production safeguards on exactly "production") + debug OFF.
    $prod = $config->getAllPublicEnvironmentVariables('production');
    expect($prod)->toMatchArray(['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);

    // Even a non-production cloud env (staging) reports "production".
    $staging = $config->getAllPublicEnvironmentVariables('staging');
    expect($staging)->toMatchArray(['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);

    // Local is left alone (keeps Laravel's own APP_ENV=local / APP_DEBUG=true).
    $local = $config->getAllPublicEnvironmentVariables('local');
    expect($local)->not->toHaveKey('APP_DEBUG');
});

test('features filter by env with enum defaults', function (): void {
    // BOOST + MCP default to local only; HORIZON to all envs; SSR to prod only.
    $config = ConfigData::from([
        'features' => ['boost', 'mcp', 'horizon', 'ssr'],
    ]);

    $local = $config->getFeatures('local');
    expect($local)->toContain(LaravelFeature::BOOST)
        ->toContain(LaravelFeature::MCP)
        ->toContain(LaravelFeature::HORIZON)->not->toContain(LaravelFeature::SSR);

    $prod = $config->getFeatures('production');
    expect($prod)->toContain(LaravelFeature::HORIZON)
        ->toContain(LaravelFeature::SSR)->not->toContain(LaravelFeature::BOOST)->not->toContain(LaravelFeature::MCP);
});

test('environment overrides can add or exclude features', function (): void {
    $config = ConfigData::from([
        'features' => ['horizon', 'boost'],
        'environments' => [
            'local' => [
                'excludeFeatures' => ['horizon'],
            ],
            'production' => [
                'addFeatures' => ['boost'],
            ],
        ],
    ]);

    expect($config->getFeatures('local'))->not->toContain(LaravelFeature::HORIZON)
        ->and($config->getFeatures('local'))->toContain(LaravelFeature::BOOST)
        ->and($config->getFeatures('production'))->toContain(LaravelFeature::HORIZON)
        ->and($config->getFeatures('production'))->toContain(LaravelFeature::BOOST);
});

test('save to file omits transient fields', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tmp = $temporaryDirectory->path();

    try {
        $config = ConfigData::from(['name' => 'demo', 'isScaffolding' => true]);
        $config->setPath($tmp);
        $config->saveToFile($tmp);

        $json = json_decode(file_get_contents("{$tmp}/.larakube.json"), true);

        // Transient/machine-specific fields are not persisted...
        expect($json)->not->toHaveKey('isScaffolding');
        expect($json)->not->toHaveKey('path');
        // ...but real fields are.
        expect($json['name'])->toBe('demo');

        // And it still round-trips with sane defaults.
        $reloaded = ConfigData::from($json);
        expect($reloaded->isScaffolding())->toBeFalse();
    } finally {
        $temporaryDirectory->delete();
    }
});

test('add environment creates a new env idempotently', function (): void {
    $config = ConfigData::from([]);

    expect($config->hasEnvironment('staging'))->toBeFalse();

    $config->addEnvironment('staging');
    expect($config->hasEnvironment('staging'))->toBeTrue()
        ->and($config->getEnvironment('staging'))->toBeInstanceOf(EnvironmentData::class);

    $config->getEnvironment('staging')->managed = ['postgres'];
    $config->addEnvironment('staging');
    expect($config->getManaged('staging'))->toBe(['postgres']);
});

test('remove environment drops it from the map', function (): void {
    $config = ConfigData::from([]);
    $config->addEnvironment('staging');
    expect($config->hasEnvironment('staging'))->toBeTrue();

    $config->removeEnvironment('staging');
    expect($config->hasEnvironment('staging'))->toBeFalse()
        ->and($config->getEnvironments())->not->toContain('staging');
});

test('set host writes per env and per service', function (): void {
    $config = ConfigData::from([]);

    $config->setHost('staging', 'web', 'staging.example.com');
    $config->setHost('staging', 'reverb', 'ws-stg.example.com');

    expect($config->getHost('staging', 'web'))->toBe('staging.example.com')
        ->and($config->getHost('staging', 'reverb'))->toBe('ws-stg.example.com')
        ->and($config->getHost('staging', 'mailpit'))->toBeNull()
        ->and($config->getHost('production', 'web'))->toBeNull();
});

test('get service host honours explicit per service override', function (): void {
    // Reverb on its own subdomain should not get prefixed off the web host.
    $config = ConfigData::from([
        'name' => 'demo',
        'environments' => [
            'production' => [
                'hosts' => [
                    'web' => 'example.com',
                    'reverb' => 'ws.example.com',
                ],
            ],
        ],
    ]);

    expect($config->getServiceHost('reverb', 'production'))->toBe('ws.example.com');
    // Services without explicit overrides still derive from the web host.
    expect($config->getServiceHost('vite', 'production'))->toBe('vite-example.com');
});

test('get service host works for any non local env not just production', function (): void {
    // If the user renames "production" to "main" or adds a "qa" env,
    // service-host resolution must still honour the configured web host.
    $config = ConfigData::from([
        'name' => 'demo',
        'environments' => [
            'qa' => ['hosts' => ['web' => 'qa.example.com']],
            'main' => ['hosts' => ['web' => 'example.com']],
        ],
    ]);

    expect($config->getServiceHost('vite', 'qa'))->toBe('vite-qa.example.com')
        ->and($config->getServiceHost('vite', 'main'))->toBe('vite-example.com')
        ->and($config->getAppUrl('qa'))->toBe('https://qa.example.com')
        ->and($config->getAppUrl('main'))->toBe('https://example.com');
});

test('get shared service host honours a larakube json override', function (): void {
    // A cloud Grafana host lives in the env hosts map like web/reverb/s3.
    $config = ConfigData::from([
        'name' => 'demo',
        'environments' => [
            'production' => [
                'hosts' => [
                    'web' => 'app.example.com',
                    'grafana' => 'metrics.example.com',
                ],
            ],
        ],
    ]);

    expect($config->getSharedServiceHost(SharedClusterService::GRAFANA, 'production'))->toBe('metrics.example.com');
});

test('get shared service host derives from web host on cloud without override', function (): void {
    $config = ConfigData::from([
        'name' => 'demo',
        'environments' => [
            'production' => ['hosts' => ['web' => 'app.example.com']],
        ],
    ]);

    // Name-less global host, derived off the web host like other services —
    // no project-name segment, unlike getServiceHost().
    expect($config->getSharedServiceHost(SharedClusterService::GRAFANA, 'production'))->toBe('grafana-app.example.com');
});

test('get shared service host uses global tld locally without project name', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'localTld' => 'test']);

    // Shared cluster hosts are name-less and follow the GLOBAL dev TLD, not
    // the project's localTld override (they're shared across all projects).
    $globalTld = GlobalConfigData::load()->getLocalTld();
    $host = $config->getSharedServiceHost(SharedClusterService::GRAFANA, 'local');

    expect($host)->toBe("grafana.{$globalTld}")->not->toContain('demo');
});

test('get manageable services lists all externalizable backing services', function (): void {
    $config = ConfigData::from([
        'database' => 'postgres',
        'cacheDriver' => 'redis',
        'scoutDriver' => 'meilisearch',
        'objectStorage' => 'minio',
    ]);

    $services = $config->getManageableServices();

    // DB, cache, search, and object storage are all offload-able.
    expect($services)->toHaveKey('postgres');
    expect($services)->toHaveKeys(['redis', 'meilisearch', 'minio']);
});

test('get manageable services excludes drivers with no network service', function (): void {
    // SQLite, database-backed cache, and database-backed scout have nothing
    // to offload to a managed provider — they must not appear.
    $config = ConfigData::from([
        'database' => 'sqlite',
        'cacheDriver' => 'database',
        'scoutDriver' => 'database',
    ]);

    expect($config->getManageableServices())->toBeEmpty();
});

test('set host creates the environment and writes into the environment map', function (): void {
    $config = ConfigData::from(['environments' => ['local' => []]]);
    expect($config->hasEnvironment('production'))->toBeFalse();

    $config->setHost('production', 'web', 'app.example.com');

    expect($config->getWebHost('production'))->toBe('app.example.com')
        ->and($config->getEnvironment('production')->hosts['web'])->toBe('app.example.com');
});

test('get web hosts returns just the primary when no additional hosts are set', function (): void {
    $config = ConfigData::from([
        'name' => 'myapp',
        'environments' => ['production' => ['hosts' => ['web' => 'app.example.com']]],
    ]);

    expect($config->getWebHosts('production'))->toBe(['app.example.com']);
});

test('get web hosts lists the primary first then additional hosts in order', function (): void {
    $config = ConfigData::from([
        'name' => 'myapp',
        'environments' => [
            'production' => [
                'hosts' => ['web' => 'app.example.com'],
                'additionalWebHosts' => ['admin.example.com', 'mybrand.io'],
            ],
        ],
    ]);

    expect($config->getWebHosts('production'))->toBe(['app.example.com', 'admin.example.com', 'mybrand.io']);
});

test('get web hosts dedupes an additional host that matches the primary', function (): void {
    $config = ConfigData::from([
        'name' => 'myapp',
        'environments' => [
            'production' => [
                'hosts' => ['web' => 'app.example.com'],
                'additionalWebHosts' => ['app.example.com', 'admin.example.com'],
            ],
        ],
    ]);

    expect($config->getWebHosts('production'))->toBe(['app.example.com', 'admin.example.com']);
});

test('add additional web host is idempotent and creates the environment if missing', function (): void {
    $config = ConfigData::from(['name' => 'myapp', 'environments' => ['local' => []]]);
    expect($config->hasEnvironment('production'))->toBeFalse();

    $config->addAdditionalWebHost('production', 'admin.example.com');
    $config->addAdditionalWebHost('production', 'admin.example.com');

    expect($config->getEnvironment('production')->additionalWebHosts)->toBe(['admin.example.com']);
});

test('remove additional web host is a no op when the environment or host is missing', function (): void {
    $config = ConfigData::from(['name' => 'myapp', 'environments' => ['local' => []]]);

    // No exception, no side effect, for an environment that doesn't exist.
    $config->removeAdditionalWebHost('production', 'admin.example.com');
    expect($config->hasEnvironment('production'))->toBeFalse();

    $config->addAdditionalWebHost('production', 'admin.example.com');
    $config->removeAdditionalWebHost('production', 'someone-else.example.com');

    expect($config->getEnvironment('production')->additionalWebHosts)->toBe(['admin.example.com']);

    $config->removeAdditionalWebHost('production', 'admin.example.com');
    expect($config->getEnvironment('production')->additionalWebHosts)->toBeEmpty();
});

test('each environment can choose its own ingress controller', function (): void {
    $config = ConfigData::from([
        'environments' => [
            'local' => [],
            'staging' => ['ingress' => 'traefik'],
            'qa' => ['ingress' => 'nginx'],
            'production' => ['ingress' => 'aws-alb'],
        ],
    ]);

    expect($config->getIngress('local'))->toEqual(IngressController::TRAEFIK)
        ->and($config->getIngress('staging'))->toEqual(IngressController::TRAEFIK)
        ->and($config->getIngress('qa'))->toEqual(IngressController::NGINX)
        ->and($config->getIngress('production'))->toEqual(IngressController::AWS_ALB);
});

test('get ingress defaults to traefik for unconfigured environments', function (): void {
    $config = ConfigData::from(['environments' => ['local' => [], 'production' => []]]);

    expect($config->getIngress('local'))->toEqual(IngressController::TRAEFIK)
        ->and($config->getIngress('production'))->toEqual(IngressController::TRAEFIK);
});

test('build wait for command', function (): void {
    // 1. System projects skip external TCP checks but still return null without waitForWeb
    $config = ConfigData::from(['isSystem' => true]);
    expect($config->buildWaitForCommand([DatabaseDriver::MYSQL]))->toBeNull();

    // 2. System project WITH waitForWeb=true returns curl check (not TCP check)
    $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL], waitForWeb: true);
    expect($command)->toContain('curl -sf http://web/up')->not->toContain('mysql');

    // 3. Normal project with MySQL returns nc command
    $config = ConfigData::from(['isSystem' => false]);
    $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL]);
    expect($command)->toContain('mysql 3306');

    // 4. Normal project with MySQL + waitForWeb includes BOTH checks
    $command = $config->buildWaitForCommand([DatabaseDriver::MYSQL], waitForWeb: true);
    expect($command)->toContain('curl -sf http://web/up')
        ->toContain('mysql 3306');

    // 5. SQLite returns null (no external service)
    expect($config->buildWaitForCommand([DatabaseDriver::SQLITE]))->toBeNull();

    // 6. SQLite + waitForWeb returns only curl check
    $command = $config->buildWaitForCommand([DatabaseDriver::SQLITE], waitForWeb: true);
    expect($command)->toContain('curl -sf http://web/up')->not->toContain('sqlite');

    // 7. Redis cache returns command on port 6379
    $command = $config->buildWaitForCommand([CacheDriver::REDIS]);
    expect($command)->toContain('redis 6379');

    // 8. Memcached cache returns command on port 11211
    $command = $config->buildWaitForCommand([CacheDriver::MEMCACHED]);
    expect($command)->toContain('memcached 11211');

    // 9. Database cache returns null
    expect($config->buildWaitForCommand([CacheDriver::DATABASE]))->toBeNull();

    // 10. Meilisearch scout returns command on port 7700
    $command = $config->buildWaitForCommand([SearchDriver::MEILISEARCH]);
    expect($command)->toContain('meilisearch 7700');

    // 11. Typesense scout returns command on port 8108
    $command = $config->buildWaitForCommand([SearchDriver::TYPESENSE]);
    expect($command)->toContain('typesense 8108');

    // 12. Database scout returns null
    expect($config->buildWaitForCommand([SearchDriver::DATABASE]))->toBeNull();

    // 13. Storage drivers return command on correct ports
    $command = $config->buildWaitForCommand([StorageDriver::MINIO]);
    expect($command)->toContain('minio 9000');

    $command = $config->buildWaitForCommand([StorageDriver::SEAWEEDFS]);
    expect($command)->toContain('seaweedfs 8333');

    $command = $config->buildWaitForCommand([StorageDriver::GARAGE]);
    expect($command)->toContain('garage 3900');
});

test('is scaffolding getter works as method', function (): void {
    // Ensures isScaffolding() can be called as a method (not just as a property).
    // This would have caught the BadMethodCallException thrown during `larakube new`.
    $config = ConfigData::from(['isScaffolding' => false]);
    expect($config->isScaffolding())->toBeFalse();

    $config->setIsScaffolding(true);
    expect($config->isScaffolding())->toBeTrue();
});

test('php version is hidden respects scaffolding', function (): void {
    // Ensures PhpVersion::isHidden() can call $config->isScaffolding() without crashing.
    // This would have caught the BadMethodCallException thrown during `larakube new`.
    $scaffolding = ConfigData::from(['isScaffolding' => true]);

    // Old versions should be hidden when scaffolding a new project (Laravel 13 requires 8.3+)
    expect(PhpVersion::PHP_8_2->isHidden($scaffolding))->toBeTrue();
    expect(PhpVersion::PHP_8_1->isHidden($scaffolding))->toBeTrue()
        ->and(PhpVersion::PHP_8_0->isHidden($scaffolding))->toBeTrue()
        ->and(PhpVersion::PHP_7_4->isHidden($scaffolding))->toBeTrue();

    // Modern versions must remain visible when scaffolding
    expect(PhpVersion::PHP_8_5->isHidden($scaffolding))->toBeFalse();
    expect(PhpVersion::PHP_8_4->isHidden($scaffolding))->toBeFalse()
        ->and(PhpVersion::PHP_8_3->isHidden($scaffolding))->toBeFalse();

    // Without scaffolding, old versions are visible
    $existing = ConfigData::from(['isScaffolding' => false]);
    expect(PhpVersion::PHP_8_2->isHidden($existing))->toBeFalse()
        ->and(PhpVersion::PHP_8_1->isHidden($existing))->toBeFalse();
});

test('watch paths default to standard laravel dirs', function (): void {
    $config = ConfigData::from([]);

    expect($config->getWatchPaths())->toBe(['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'composer.lock', '.env']);
});

test('watch paths can be overridden via blueprint', function (): void {
    $config = ConfigData::from([
        'watchPaths' => ['app', 'domain', 'modules'],
    ]);

    expect($config->getWatchPaths())->toBe(['app', 'domain', 'modules']);
});

test('provision test db defaults to false', function (): void {
    $config = ConfigData::from([]);

    expect($config->getProvisionTestDb())->toBeFalse();
});

test('provision test db can be enabled via blueprint', function (): void {
    $config = ConfigData::from(['provisionTestDb' => true]);

    expect($config->getProvisionTestDb())->toBeTrue();
});

test('local tld defaults to global when project has no override', function (): void {
    $config = ConfigData::from(['name' => 'demo']);

    expect($config->hasLocalTld())->toBeFalse()
        ->and($config->getLocalTld())->toBe('kube');
});

test('local tld override wins over the global default', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'localTld' => 'test']);

    expect($config->hasLocalTld())->toBeTrue()
        ->and($config->getLocalTld())->toBe('test')
        ->and($config->getAppUrl('local'))->toBe('https://demo.test')
        ->and($config->getServiceHost('vite', 'local'))->toBe('vite.demo.test');
});

test('set local tld normalizes and can be cleared', function (): void {
    $config = ConfigData::from(['name' => 'demo']);

    $config->setLocalTld('.TEST');
    expect($config->getLocalTld())->toBe('test');

    $config->setLocalTld(null);
    expect($config->hasLocalTld())->toBeFalse()
        ->and($config->getLocalTld())->toBe('kube');
});

test('add additional extension appends uniquely', function (): void {
    $config = ConfigData::from(['name' => 'demo']);

    $config->addAdditionalExtension('imagick');
    $config->addAdditionalExtension('gd');
    $config->addAdditionalExtension('imagick'); // duplicate — must not double up

    expect($config->getAdditionalExtensions())->toBe(['imagick', 'gd']);
});

test('remove additional extension drops and reindexes', function (): void {
    $config = ConfigData::from(['name' => 'demo', 'additionalExtensions' => ['imagick', 'gd', 'redis']]);

    $config->removeAdditionalExtension('gd');
    $config->removeAdditionalExtension('missing'); // no-op — must not error

    expect($config->getAdditionalExtensions())->toBe(['imagick', 'redis']);
});

test('service connection variable names include plex backed drivers', function (): void {
    // Regression guard: `up`'s ConfigMap injection for local uses this list
    // as its sole allowlist for non-secret vars — excluding a plex-backed
    // driver's keys (DB_HOST, REDIS_HOST, …) meant they never made it into
    // the ConfigMap once joined to Plex, silently stranding the pod on a
    // self-hosted host that plex:join had already torn down. The key NAMES
    // are identical whether self-hosted or Commons-backed; only the VALUES
    // (already written into .env by plex:join) differ.
    $selfHosted = ConfigData::from([
        'name' => 'demo',
        'database' => 'postgres',
    ]);

    $plexBacked = ConfigData::from([
        'name' => 'demo',
        'database' => 'postgres',
        'environments' => [
            'local' => ['managed' => ['postgres'], 'plex' => ['postgres']],
        ],
    ]);

    expect($selfHosted->getServiceConnectionVariableNames('local'))->toContain('DB_HOST')
        ->and($plexBacked->getServiceConnectionVariableNames('local'))->toBe($selfHosted->getServiceConnectionVariableNames('local'));
});
