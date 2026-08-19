<?php

use App\Contracts\HasHosts;
use App\Contracts\HasPromptableHosts;
use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\SearchDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;

test('every host-publishing component declares its overrideable services', function (): void {
    // Cloud-overrideable components — must have entries in getHostServices.
    expect(LaravelFeature::REVERB->getHostServices())->toHaveKey('reverb')
        ->and(SearchDriver::MEILISEARCH->getHostServices())->toHaveKey('meilisearch')
        ->and(SearchDriver::TYPESENSE->getHostServices())
        ->toHaveKeys(['typesense', 'typesense-dashboard'])
        ->and(StorageDriver::MINIO->getHostServices())
        ->toHaveKeys(['s3', 's3-console']);
});

test('local-console components opt out of host overrides via empty getHostServices', function (): void {
    // DB and Cache consoles are local-only with baked-in .kube domains;
    // they MUST NOT show up in the env wizard's host-override prompts.
    expect(DatabaseDriver::MYSQL->getHostServices())->toBeEmpty()
        ->and(DatabaseDriver::POSTGRESQL->getHostServices())->toBeEmpty()
        ->and(CacheDriver::REDIS->getHostServices())->toBeEmpty()
        ->and(CacheDriver::MEMCACHED->getHostServices())->toBeEmpty()
        ->and(ServerVariation::FRANKENPHP->getHostServices())->toBeEmpty()
        ->and(Blueprint::FILAMENT->getHostServices())->toBeEmpty();
});

test('databases publish no ingress host — consoles are shared companions, not per-project routes', function (): void {
    $config = ConfigData::from([
        'name' => 'demo',
        'databases' => ['mysql'],
        'environments' => [
            'local' => [],
            'production' => ['hosts' => ['web' => 'example.com']],
        ],
    ]);

    // The DB console moved to the shared CompanionDriver apps (phpmyadmin.kube
    // in larakube-companions); the driver itself no longer publishes a host in any env.
    expect(DatabaseDriver::MYSQL->getHosts($config, 'local'))->toBeEmpty()
        ->and(DatabaseDriver::MYSQL->getHosts($config, 'production'))->toBeEmpty();

    // ...so nothing DB-shaped leaks into the merged host map either.
    expect(array_keys($config->getAllHosts('local')))->not->toContain('mysql.demo.kube');
    expect(array_keys($config->getAllHosts('production')))->not->toContain('mysql.demo.kube');
});

test('DerivesHostsFromServices trait honours per-env overrides through getServiceHost', function (): void {
    $config = ConfigData::from([
        'name' => 'demo',
        'features' => ['reverb'],
        'environments' => [
            'production' => [
                'hosts' => [
                    'web' => 'example.com',
                    'reverb' => 'ws.example.com',
                ],
            ],
        ],
    ]);

    $hosts = LaravelFeature::REVERB->getHosts($config, 'production');

    expect($hosts)->toHaveKey('ws.example.com', 'Reverb WebSocket');
});

test('DerivesHostsFromServices trait falls back to prefix pattern when no override', function (): void {
    $config = ConfigData::from([
        'name' => 'demo',
        'features' => ['reverb'],
        'environments' => [
            'production' => ['hosts' => ['web' => 'example.com']],
        ],
    ]);

    $hosts = LaravelFeature::REVERB->getHosts($config, 'production');

    expect($hosts)->toHaveKey('reverb-example.com', 'Reverb WebSocket');
});

test('storage driver exposes both s3 and console as overrideable services', function (): void {
    $config = ConfigData::from([
        'name' => 'demo',
        'objectStorage' => 'minio',
        'environments' => [
            'production' => [
                'hosts' => [
                    'web' => 'example.com',
                    's3' => 'cdn.example.com',
                    // s3-console intentionally omitted — falls back to prefix.
                ],
            ],
        ],
    ]);

    $hosts = StorageDriver::MINIO->getHosts($config, 'production');

    expect($hosts)
        ->toHaveKey('cdn.example.com', 'MinIO S3 API')
        ->toHaveKey('s3-console-example.com', 'MinIO Console');
});

test('storage driver skips its manifests in envs where it is externally managed', function (): void {
    $config = ConfigData::from([
        'name' => 'demo',
        'objectStorage' => 'minio',
        'environments' => [
            'local' => [],
            'production' => ['managed' => ['minio']],
        ],
    ]);

    $files = StorageDriver::MINIO->getManifestFiles($config);

    // Local still deploys MinIO; production is skipped (managed via S3/Spaces).
    expect($files)->toHaveKey('local')
        ->and($files)->not->toHaveKey('production');
});

test('database and cache consoles never appear in getAllHosts — shared companions own them now', function (): void {
    $config = ConfigData::from([
        'name' => 'demo',
        'databases' => ['mysql'],
        'cacheDriver' => 'redis',
        'environments' => ['local' => []],
    ]);

    $hosts = array_keys($config->getAllHosts('local'));

    expect($hosts)->not->toContain('mysql.demo.kube')
        ->and($hosts)->not->toContain('redis.demo.kube');
});

test('getAllHosts includes additionalWebHosts, labeled distinctly from the primary application host', function (): void {
    $config = ConfigData::from([
        'name' => 'demo',
        'environments' => [
            'production' => [
                'hosts' => ['web' => 'app.example.com'],
                'additionalWebHosts' => ['admin.example.com', 'mybrand.io'],
            ],
        ],
    ]);

    $hosts = $config->getAllHosts('production');

    expect($hosts)->toHaveKey('app.example.com', 'Primary Application')
        ->and($hosts)->toHaveKey('admin.example.com', 'Web (alias)')
        ->and($hosts)->toHaveKey('mybrand.io', 'Web (alias)');
});

test('only client-facing endpoints are promptable for custom hosts', function (): void {
    // Reverb (ws) and S3 are worth a vanity subdomain prompt...
    expect(LaravelFeature::REVERB)->toBeInstanceOf(HasPromptableHosts::class)
        ->and(LaravelFeature::REVERB->getPromptableHostServices())->toHaveKey('reverb')
        ->and(StorageDriver::MINIO)->toBeInstanceOf(HasPromptableHosts::class)
        ->and(StorageDriver::MINIO->getPromptableHostServices())->toHaveKey('s3');

    // The MinIO admin console is not promptable; only the S3 API is.
    expect(StorageDriver::MINIO->getPromptableHostServices())->not->toHaveKey('s3-console');

    // Search drivers do NOT implement HasPromptableHosts at all, so the env
    // wizard never prompts for a Meilisearch/Typesense console host (they
    // still publish a derived ingress host via getHostServices()).
    expect(SearchDriver::MEILISEARCH)->not->toBeInstanceOf(HasPromptableHosts::class)
        ->and(SearchDriver::TYPESENSE)->not->toBeInstanceOf(HasPromptableHosts::class);

});

test('all HasHosts implementers conform to the new contract', function (): void {
    // Catches future enums that implement HasHosts but forget getHostServices.
    $implementers = [
        LaravelFeature::REVERB,
        SearchDriver::DATABASE,
        StorageDriver::GARAGE,
        DatabaseDriver::SQLITE,
        CacheDriver::DATABASE,
        ServerVariation::FPM_NGINX,
        Blueprint::FILAMENT,
    ];

    foreach ($implementers as $component) {
        expect($component)->toBeInstanceOf(HasHosts::class)
            ->and($component->getHostServices())->toBeArray();
    }
});
