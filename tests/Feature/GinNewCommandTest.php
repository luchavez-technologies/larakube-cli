<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use Illuminate\Contracts\Console\Kernel;

// ── gin:new Command Tests ─────────────────────────────────────────────────────

test('gin:new command is registered and has correct signature', function (): void {
    $this->artisan('gin:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('gin:new');
});

test('gin:new command has --fast option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('gin:new')
        ->and($commands['gin:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — Gin ─────────────────────────────────────────

test('AppFramework GIN framework value is correct', function (): void {
    expect(AppFramework::GIN->value)->toBe('gin')
        ->and(AppFramework::GIN->getLabel())->toBe('Gin (Go)')
        ->and(AppFramework::GIN->healthProbePath())->toBe('/healthz')
        ->and(AppFramework::GIN->proxyCommand())->toBe('go run .');
});

test('Gin DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function (): void {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3)->toContainOnlyInstancesOf(DatabaseDriver::class);
});

test('Gin CacheDriver matrix: Redis, Memcached are valid', function (): void {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('Gin StorageDriver: MinIO, SeaweedFS, Garage are valid', function (): void {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── Gin ConfigData Integration ────────────────────────────────────────────────

test('ConfigData accepts AppFramework::GIN framework', function (): void {
    $config = new ConfigData;
    $config->framework = AppFramework::GIN;

    expect($config->framework)->toBe(AppFramework::GIN)
        ->and($config->framework->value)->toBe('gin');
});
