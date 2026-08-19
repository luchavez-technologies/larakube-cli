<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use Illuminate\Contracts\Console\Kernel;

// ── fastapi:new Command Tests ──────────────────────────────────────────────────

test('fastapi:new command is registered and has correct signature', function (): void {
    $this->artisan('fastapi:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('fastapi:new');
});

test('fastapi:new command has --fast option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('fastapi:new')
        ->and($commands['fastapi:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — FastAPI ──────────────────────────────────────

test('AppFramework FASTAPI framework value is correct', function (): void {
    expect(AppFramework::FASTAPI->value)->toBe('fastapi')
        ->and(AppFramework::FASTAPI->getLabel())->toBe('FastAPI')
        ->and(AppFramework::FASTAPI->healthProbePath())->toBe('/healthz')
        ->and(AppFramework::FASTAPI->proxyCommand())->toBe('python');
});

test('FastAPI DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function (): void {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3)->toContainOnlyInstancesOf(DatabaseDriver::class);
});

test('FastAPI CacheDriver matrix: Redis, Memcached are valid', function (): void {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('FastAPI StorageDriver: MinIO, SeaweedFS, Garage are valid', function (): void {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── FastAPI ConfigData Integration ─────────────────────────────────────────────

test('ConfigData accepts AppFramework::FASTAPI framework', function (): void {
    $config = new ConfigData;
    $config->framework = AppFramework::FASTAPI;

    expect($config->framework)->toBe(AppFramework::FASTAPI)
        ->and($config->framework->value)->toBe('fastapi');
});
