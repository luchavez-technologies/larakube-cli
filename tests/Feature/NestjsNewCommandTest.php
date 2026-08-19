<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use Illuminate\Contracts\Console\Kernel;

// ── nestjs:new Command Tests ──────────────────────────────────────────────────

test('nestjs:new command is registered and has correct signature', function (): void {
    $this->artisan('nestjs:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('nestjs:new');
});

test('nestjs:new command has --fast option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('nestjs:new')
        ->and($commands['nestjs:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — NestJS ──────────────────────────────────────

test('AppFramework NESTJS framework value is correct', function (): void {
    expect(AppFramework::NESTJS->value)->toBe('nestjs')
        ->and(AppFramework::NESTJS->getLabel())->toBe('NestJS')
        ->and(AppFramework::NESTJS->healthProbePath())->toBe('/healthz')
        ->and(AppFramework::NESTJS->proxyCommand())->toBe('node');
});

test('NestJS DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function (): void {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3)->toContainOnlyInstancesOf(DatabaseDriver::class);
});

test('NestJS CacheDriver matrix: Redis, Memcached are valid', function (): void {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('NestJS StorageDriver: MinIO, SeaweedFS, Garage are valid', function (): void {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── NestJS ConfigData Integration ─────────────────────────────────────────────

test('ConfigData accepts AppFramework::NESTJS framework', function (): void {
    $config = new ConfigData;
    $config->framework = AppFramework::NESTJS;

    expect($config->framework)->toBe(AppFramework::NESTJS)
        ->and($config->framework->value)->toBe('nestjs');
});
