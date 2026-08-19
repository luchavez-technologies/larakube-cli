<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use Illuminate\Contracts\Console\Kernel;

// ── adonisjs:new Command Tests ────────────────────────────────────────────────

test('adonisjs:new command is registered and has correct signature', function (): void {
    $this->artisan('adonisjs:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('adonisjs:new');
});

test('adonisjs:new command has --fast option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('adonisjs:new')
        ->and($commands['adonisjs:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — AdonisJS ────────────────────────────────────

test('AppFramework ADONISJS framework value is correct', function (): void {
    expect(AppFramework::ADONISJS->value)->toBe('adonisjs')
        ->and(AppFramework::ADONISJS->getLabel())->toBe('AdonisJS')
        ->and(AppFramework::ADONISJS->healthProbePath())->toBe('/healthz')
        ->and(AppFramework::ADONISJS->proxyCommand())->toBe('node ace');
});

test('AdonisJS DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function (): void {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3)->toContainOnlyInstancesOf(DatabaseDriver::class);
});

test('AdonisJS CacheDriver matrix: Redis, Memcached are valid', function (): void {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('AdonisJS StorageDriver: MinIO, SeaweedFS, Garage are valid', function (): void {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── AdonisJS ConfigData Integration ──────────────────────────────────────────

test('ConfigData accepts AppFramework::ADONISJS framework', function (): void {
    $config = new ConfigData;
    $config->framework = AppFramework::ADONISJS;

    expect($config->framework)->toBe(AppFramework::ADONISJS)
        ->and($config->framework->value)->toBe('adonisjs');
});
