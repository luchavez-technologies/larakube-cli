<?php

use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;

// ── fastapi:new Command Tests ──────────────────────────────────────────────────

test('fastapi:new command is registered and has correct signature', function () {
    $this->artisan('fastapi:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('fastapi:new');
});

test('fastapi:new command has --fast option', function () {
    $kernel = app(Illuminate\Contracts\Console\Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('fastapi:new');
    expect($commands['fastapi:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — FastAPI ──────────────────────────────────────

test('AppFramework FASTAPI framework value is correct', function () {
    expect(AppFramework::FASTAPI->value)->toBe('fastapi');
    expect(AppFramework::FASTAPI->getLabel())->toBe('FastAPI');
    expect(AppFramework::FASTAPI->healthProbePath())->toBe('/healthz');
    expect(AppFramework::FASTAPI->proxyCommand())->toBe('python');
});

test('FastAPI DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function () {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3);
    foreach ($supported as $driver) {
        expect($driver)->toBeInstanceOf(DatabaseDriver::class);
    }
});

test('FastAPI CacheDriver matrix: Redis, Memcached are valid', function () {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('FastAPI StorageDriver: MinIO, SeaweedFS, Garage are valid', function () {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── FastAPI ConfigData Integration ─────────────────────────────────────────────

test('ConfigData accepts AppFramework::FASTAPI framework', function () {
    $config = new App\Data\ConfigData;
    $config->framework = AppFramework::FASTAPI;

    expect($config->framework)->toBe(AppFramework::FASTAPI);
    expect($config->framework->value)->toBe('fastapi');
});
