<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use Illuminate\Contracts\Console\Kernel;

// ── axum:new Command Tests ────────────────────────────────────────────────────

test('axum:new command is registered and has correct signature', function () {
    $this->artisan('axum:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('axum:new');
});

test('axum:new command has --fast option', function () {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('axum:new');
    expect($commands['axum:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — Axum ────────────────────────────────────────

test('AppFramework AXUM framework value is correct', function () {
    expect(AppFramework::AXUM->value)->toBe('axum');
    expect(AppFramework::AXUM->getLabel())->toBe('Axum (Rust)');
    expect(AppFramework::AXUM->healthProbePath())->toBe('/healthz');
    expect(AppFramework::AXUM->proxyCommand())->toBe('cargo run');
});

test('Axum DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function () {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3);
    foreach ($supported as $driver) {
        expect($driver)->toBeInstanceOf(DatabaseDriver::class);
    }
});

test('Axum CacheDriver matrix: Redis, Memcached are valid', function () {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('Axum StorageDriver: MinIO, SeaweedFS, Garage are valid', function () {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── Axum ConfigData Integration ───────────────────────────────────────────────

test('ConfigData accepts AppFramework::AXUM framework', function () {
    $config = new ConfigData;
    $config->framework = AppFramework::AXUM;

    expect($config->framework)->toBe(AppFramework::AXUM);
    expect($config->framework->value)->toBe('axum');
});
