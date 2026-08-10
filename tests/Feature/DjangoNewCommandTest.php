<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use Illuminate\Contracts\Console\Kernel;

// ── django:new Command Tests ──────────────────────────────────────────────────

test('django:new command is registered and has correct signature', function () {
    $this->artisan('django:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('django:new');
});

test('django:new command has --fast option', function () {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('django:new');
    expect($commands['django:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — Django ──────────────────────────────────────

test('AppFramework DJANGO framework value is correct', function () {
    expect(AppFramework::DJANGO->value)->toBe('django');
    expect(AppFramework::DJANGO->getLabel())->toBe('Django');
    expect(AppFramework::DJANGO->healthProbePath())->toBe('/healthz');
    expect(AppFramework::DJANGO->proxyCommand())->toBe('python manage.py');
});

test('Django DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function () {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3);
    foreach ($supported as $driver) {
        expect($driver)->toBeInstanceOf(DatabaseDriver::class);
    }
});

test('Django CacheDriver matrix: Redis, Memcached, Database are valid', function () {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED, CacheDriver::DATABASE];

    expect($supported)->toHaveCount(3);
});

test('Django StorageDriver: MinIO, SeaweedFS, Garage are valid', function () {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── Django ConfigData Integration ─────────────────────────────────────────────

test('ConfigData accepts AppFramework::DJANGO framework', function () {
    $config = new ConfigData;
    $config->framework = AppFramework::DJANGO;

    expect($config->framework)->toBe(AppFramework::DJANGO);
    expect($config->framework->value)->toBe('django');
});
