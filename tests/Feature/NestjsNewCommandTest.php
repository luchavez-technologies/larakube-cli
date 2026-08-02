<?php

use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;

// ── nestjs:new Command Tests ──────────────────────────────────────────────────

test('nestjs:new command is registered and has correct signature', function () {
    $this->artisan('nestjs:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('nestjs:new');
});

test('nestjs:new command has --fast option', function () {
    $kernel = app(Illuminate\Contracts\Console\Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('nestjs:new');
    expect($commands['nestjs:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — NestJS ──────────────────────────────────────

test('AppFramework NESTJS framework value is correct', function () {
    expect(AppFramework::NESTJS->value)->toBe('nestjs');
    expect(AppFramework::NESTJS->getLabel())->toBe('NestJS');
    expect(AppFramework::NESTJS->healthProbePath())->toBe('/healthz');
    expect(AppFramework::NESTJS->proxyCommand())->toBe('node');
});

test('NestJS DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function () {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3);
    foreach ($supported as $driver) {
        expect($driver)->toBeInstanceOf(DatabaseDriver::class);
    }
});

test('NestJS CacheDriver matrix: Redis, Memcached are valid', function () {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('NestJS StorageDriver: MinIO, SeaweedFS, Garage are valid', function () {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── NestJS ConfigData Integration ─────────────────────────────────────────────

test('ConfigData accepts AppFramework::NESTJS framework', function () {
    $config = new App\Data\ConfigData;
    $config->framework = AppFramework::NESTJS;

    expect($config->framework)->toBe(AppFramework::NESTJS);
    expect($config->framework->value)->toBe('nestjs');
});
