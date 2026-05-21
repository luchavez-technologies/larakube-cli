<?php

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\PhpVersion;
use App\Enums\ServerVariation;

test('Databases: MySQL, MariaDB, PostgreSQL, and MongoDB generate correct manifests', function () {
    $drivers = [
        ['driver' => DatabaseDriver::MYSQL, 'file' => 'base/mysql-deployment.yaml', 'port' => 3306],
        ['driver' => DatabaseDriver::MARIADB, 'file' => 'base/mariadb-deployment.yaml', 'port' => 3306],
        ['driver' => DatabaseDriver::POSTGRESQL, 'file' => 'base/postgres-deployment.yaml', 'port' => 5432],
        ['driver' => DatabaseDriver::MONGODB, 'file' => 'base/mongodb-statefulset.yaml', 'port' => 27017],
    ];

    foreach ($drivers as $meta) {
        $driver = $meta['driver'];
        $config = new ConfigData(name: 'db-test-'.$driver->value);
        $config->setServerVariation(ServerVariation::FPM_NGINX);
        $config->setPhpVersion(PhpVersion::PHP_8_5);
        $config->setDatabase($driver);

        $manifests = generateManifestsAsArray($config);

        expect($manifests)->toHaveKey($meta['file']);

        $doc = $manifests[$meta['file']];
        // Database manifests are multi-document (Workload + Service)
        $workload = $doc[0];
        $container = $workload['spec']['template']['spec']['containers'][0];
        expect($container['ports'][0]['containerPort'])->toBe($meta['port']);

        $kustomization = $manifests['base/kustomization.yaml'];
        expect($kustomization['resources'])->toContain(basename($meta['file']));
    }
});

test('Caching: Redis and Memcached generate correct manifests', function () {
    $drivers = [
        ['driver' => CacheDriver::REDIS, 'file' => 'base/redis-deployment.yaml', 'port' => 6379],
        ['driver' => CacheDriver::MEMCACHED, 'file' => 'base/memcached-deployment.yaml', 'port' => 11211],
    ];

    foreach ($drivers as $meta) {
        $driver = $meta['driver'];
        $config = new ConfigData(name: 'cache-test-'.$driver->value);
        $config->setServerVariation(ServerVariation::FPM_NGINX);
        $config->setPhpVersion(PhpVersion::PHP_8_5);
        $config->setCacheDriver($driver);
        $config->setDatabase(DatabaseDriver::SQLITE);

        $manifests = generateManifestsAsArray($config);

        expect($manifests)->toHaveKey($meta['file']);

        $doc = $manifests[$meta['file']];
        // Cache manifests are multi-document (Deployment + Service)
        $deployment = $doc[0];
        $container = $deployment['spec']['template']['spec']['containers'][0];
        expect($container['ports'][0]['containerPort'])->toBe($meta['port']);

        $kustomization = $manifests['base/kustomization.yaml'];
        expect($kustomization['resources'])->toContain(basename($meta['file']));
    }
});

test('Frontend: Node pod is generated only when required', function () {
    $stacks = [
        ['stack' => FrontendStack::REACT, 'shouldExist' => true],
        ['stack' => FrontendStack::VUE, 'shouldExist' => true],
        ['stack' => FrontendStack::SVELTE, 'shouldExist' => true],
        ['stack' => FrontendStack::LIVEWIRE, 'shouldExist' => false],
    ];

    foreach ($stacks as $meta) {
        $stack = $meta['stack'];
        $shouldExist = $meta['shouldExist'];
        $config = new ConfigData(name: 'frontend-test-'.$stack->value);
        $config->setServerVariation(ServerVariation::FPM_NGINX);
        $config->setPhpVersion(PhpVersion::PHP_8_5);
        $config->setFrontend($stack);
        $config->setDatabase(DatabaseDriver::SQLITE);

        $manifests = generateManifestsAsArray($config);

        if ($shouldExist) {
            expect($manifests)->toHaveKey('overlays/local/node-deployment.yaml');
        } else {
            expect($manifests)->not->toHaveKey('overlays/local/node-deployment.yaml');
        }
    }
});

test('Server Variations: containerPort and Ingress scheme', function () {
    $variations = [
        ServerVariation::FPM_NGINX,
        ServerVariation::FRANKENPHP,
        ServerVariation::FPM_APACHE,
    ];

    foreach ($variations as $variation) {
        $config = new ConfigData(name: 'server-test-'.$variation->value);
        $config->setServerVariation($variation);
        $config->setPhpVersion(PhpVersion::PHP_8_5);
        $config->setDatabase(DatabaseDriver::SQLITE);

        $manifests = generateManifestsAsArray($config);

        // Verify containerPort in base/laravel.yaml
        expect($manifests)->toHaveKey('base/laravel.yaml');
        $laravelDocs = $manifests['base/laravel.yaml'];

        // Find Deployment
        $deployment = collect($laravelDocs)->firstWhere('kind', 'Deployment');
        expect($deployment)->not->toBeNull();
        $container = $deployment['spec']['template']['spec']['containers'][0];
        expect($container['ports'][0]['containerPort'])->toBe(8080);

        // Find Ingress
        $ingress = collect($laravelDocs)->firstWhere('kind', 'Ingress');
        expect($ingress)->not->toBeNull();
        $annotations = $ingress['metadata']['annotations'];
        expect($annotations['traefik.ingress.kubernetes.io/service.serversscheme'])->toBe('http');
    }
});

test('Deployment Strategies: PVC access modes', function () {
    $strategies = [
        ['strategy' => DeploymentStrategy::SINGLE_NODE, 'accessMode' => 'ReadWriteOnce'],
        ['strategy' => DeploymentStrategy::MULTI_NODE_HA, 'accessMode' => 'ReadWriteMany'],
    ];

    foreach ($strategies as $meta) {
        $strategy = $meta['strategy'];
        $accessMode = $meta['accessMode'];
        $config = new ConfigData(name: 'strategy-test-'.$strategy->value);
        $config->setServerVariation(ServerVariation::FPM_NGINX);
        $config->setPhpVersion(PhpVersion::PHP_8_5);
        $config->setStrategy($strategy);
        $config->setDatabase(DatabaseDriver::SQLITE);

        $manifests = generateManifestsAsArray($config);

        expect($manifests)->toHaveKey('base/volumes.yaml');
        $volumes = $manifests['base/volumes.yaml'];

        // base/volumes.yaml is a multi-document YAML (laravel-storage-pvc and laravel-data-pvc)
        expect($volumes)->toBeArray();
        expect($volumes[0]['spec']['accessModes'][0])->toBe($accessMode);
        expect($volumes[1]['spec']['accessModes'][0])->toBe($accessMode);
    }
});

test('Structural Verification: Horizon includes Redis and secondary deployment', function () {
    $config = new ConfigData(name: 'horizon-app');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setPhpVersion(PhpVersion::PHP_8_5);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addFeature(LaravelFeature::HORIZON);

    $manifests = generateManifestsAsArray($config);

    // 1. Verify Redis is present in base
    expect($manifests)->toHaveKey('base/redis-deployment.yaml');

    // 2. Verify Horizon deployment exists
    expect($manifests)->toHaveKey('base/horizon-deployment.yaml');

    // 3. Verify Horizon deployment uses the correct artisan command
    $horizon = $manifests['base/horizon-deployment.yaml'];
    $containers = $horizon['spec']['template']['spec']['containers'];
    expect($containers[0]['args'])->toBe(['php', 'artisan', 'horizon']);

    // 4. Verify Kustomization contains both
    $kustomization = $manifests['base/kustomization.yaml'];
    expect($kustomization['resources'])->toContain('redis-deployment.yaml');
    expect($kustomization['resources'])->toContain('horizon-deployment.yaml');
});

test('Structural Verification: Reverb includes Service and Deployment', function () {
    $config = new ConfigData(name: 'reverb-app');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setPhpVersion(PhpVersion::PHP_8_5);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addFeature(LaravelFeature::REVERB);

    $manifests = generateManifestsAsArray($config);

    // Reverb manifest is a multi-document YAML (Deployment + Service)
    expect($manifests)->toHaveKey('base/reverb-deployment.yaml');
    $docs = $manifests['base/reverb-deployment.yaml'];

    expect($docs)->toBeArray();
    expect($docs[0]['kind'])->toBe('Deployment');
    expect($docs[1]['kind'])->toBe('Service');

    // Verify ports
    expect($docs[0]['spec']['template']['spec']['containers'][0]['ports'][0]['containerPort'])->toBe(8081);
    expect($docs[1]['spec']['ports'][0]['port'])->toBe(8080);
    expect($docs[1]['spec']['ports'][0]['targetPort'])->toBe(8081);
});

test('Structural Verification: Kitchen Sink includes ALL expected manifests', function () {
    $config = new ConfigData(name: 'kitchen-sink');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setPhpVersion(PhpVersion::PHP_8_5);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addFeature(
        LaravelFeature::QUEUES,
        LaravelFeature::TASK_SCHEDULING,
        LaravelFeature::REVERB,
        LaravelFeature::MCP,
        LaravelFeature::BOOST
    );

    $manifests = generateManifestsAsArray($config);

    // Hard assertions on file existence - snapshots might miss these if broken, but this WON'T.
    expect($manifests)->toHaveKey('base/queues-deployment.yaml');
    expect($manifests)->toHaveKey('base/scheduler-cronjob.yaml');
    expect($manifests)->toHaveKey('base/reverb-deployment.yaml');

    // Verify Kustomization list
    $kustomization = $manifests['base/kustomization.yaml'];
    expect($kustomization['resources'])->toContain('queues-deployment.yaml');
    expect($kustomization['resources'])->toContain('scheduler-cronjob.yaml');
    expect($kustomization['resources'])->toContain('reverb-deployment.yaml');
});
