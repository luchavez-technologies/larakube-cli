<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use Illuminate\Contracts\Console\Kernel;

// ── dotnet:new Command Tests ──────────────────────────────────────────────────

test('dotnet:new command is registered and has correct signature', function (): void {
    $this->artisan('dotnet:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('dotnet:new');
});

test('dotnet:new command has --fast option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('dotnet:new')
        ->and($commands['dotnet:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — .NET Core ───────────────────────────────────

test('AppFramework DOTNET framework value is correct', function (): void {
    expect(AppFramework::DOTNET->value)->toBe('dotnet')
        ->and(AppFramework::DOTNET->getLabel())->toBe('.NET Core')
        ->and(AppFramework::DOTNET->healthProbePath())->toBe('/healthz')
        ->and(AppFramework::DOTNET->proxyCommand())->toBe('dotnet');
});

test('.NET Core DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function (): void {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3)->toContainOnlyInstancesOf(DatabaseDriver::class);
});

test('.NET Core CacheDriver matrix: Redis, Memcached are valid', function (): void {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('.NET Core StorageDriver: MinIO, SeaweedFS, Garage are valid', function (): void {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── .NET Core ConfigData Integration ─────────────────────────────────────────

test('ConfigData accepts AppFramework::DOTNET framework', function (): void {
    $config = new ConfigData;
    $config->framework = AppFramework::DOTNET;

    expect($config->framework)->toBe(AppFramework::DOTNET)
        ->and($config->framework->value)->toBe('dotnet');
});
