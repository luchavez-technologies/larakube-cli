<?php

use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;

// ── dotnet:new Command Tests ──────────────────────────────────────────────────

test('dotnet:new command is registered and has correct signature', function () {
    $this->artisan('dotnet:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('dotnet:new');
});

test('dotnet:new command has --fast option', function () {
    $kernel = app(Illuminate\Contracts\Console\Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('dotnet:new');
    expect($commands['dotnet:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — .NET Core ───────────────────────────────────

test('AppFramework DOTNET framework value is correct', function () {
    expect(AppFramework::DOTNET->value)->toBe('dotnet');
    expect(AppFramework::DOTNET->getLabel())->toBe('.NET Core');
    expect(AppFramework::DOTNET->healthProbePath())->toBe('/healthz');
    expect(AppFramework::DOTNET->proxyCommand())->toBe('dotnet');
});

test('.NET Core DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function () {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3);
    foreach ($supported as $driver) {
        expect($driver)->toBeInstanceOf(DatabaseDriver::class);
    }
});

test('.NET Core CacheDriver matrix: Redis, Memcached are valid', function () {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('.NET Core StorageDriver: MinIO, SeaweedFS, Garage are valid', function () {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── .NET Core ConfigData Integration ─────────────────────────────────────────

test('ConfigData accepts AppFramework::DOTNET framework', function () {
    $config = new App\Data\ConfigData;
    $config->framework = AppFramework::DOTNET;

    expect($config->framework)->toBe(AppFramework::DOTNET);
    expect($config->framework->value)->toBe('dotnet');
});
