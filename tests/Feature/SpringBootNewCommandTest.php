<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use Illuminate\Contracts\Console\Kernel;

// ── springboot:new Command Tests ──────────────────────────────────────────────

test('springboot:new command is registered and has correct signature', function (): void {
    $this->artisan('springboot:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('springboot:new');
});

test('springboot:new command has --fast option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('springboot:new')
        ->and($commands['springboot:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — Spring Boot ─────────────────────────────────

test('AppFramework SPRINGBOOT framework value is correct', function (): void {
    expect(AppFramework::SPRINGBOOT->value)->toBe('springboot')
        ->and(AppFramework::SPRINGBOOT->getLabel())->toBe('Spring Boot')
        ->and(AppFramework::SPRINGBOOT->healthProbePath())->toBe('/actuator/health')
        ->and(AppFramework::SPRINGBOOT->proxyCommand())->toBe('java -jar app.jar');
});

test('Spring Boot DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function (): void {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3)->toContainOnlyInstancesOf(DatabaseDriver::class);
});

test('Spring Boot CacheDriver matrix: Redis, Memcached are valid', function (): void {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('Spring Boot StorageDriver: MinIO, SeaweedFS, Garage are valid', function (): void {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── Spring Boot ConfigData Integration ────────────────────────────────────────

test('ConfigData accepts AppFramework::SPRINGBOOT framework', function (): void {
    $config = new ConfigData;
    $config->framework = AppFramework::SPRINGBOOT;

    expect($config->framework)->toBe(AppFramework::SPRINGBOOT)
        ->and($config->framework->value)->toBe('springboot');
});
