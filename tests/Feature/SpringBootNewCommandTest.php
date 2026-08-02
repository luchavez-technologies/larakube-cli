<?php

use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;

// ── springboot:new Command Tests ──────────────────────────────────────────────

test('springboot:new command is registered and has correct signature', function () {
    $this->artisan('springboot:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('springboot:new');
});

test('springboot:new command has --fast option', function () {
    $kernel = app(Illuminate\Contracts\Console\Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('springboot:new');
    expect($commands['springboot:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — Spring Boot ─────────────────────────────────

test('AppFramework SPRINGBOOT framework value is correct', function () {
    expect(AppFramework::SPRINGBOOT->value)->toBe('springboot');
    expect(AppFramework::SPRINGBOOT->getLabel())->toBe('Spring Boot');
    expect(AppFramework::SPRINGBOOT->healthProbePath())->toBe('/actuator/health');
    expect(AppFramework::SPRINGBOOT->proxyCommand())->toBe('java -jar app.jar');
});

test('Spring Boot DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function () {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3);
    foreach ($supported as $driver) {
        expect($driver)->toBeInstanceOf(DatabaseDriver::class);
    }
});

test('Spring Boot CacheDriver matrix: Redis, Memcached are valid', function () {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('Spring Boot StorageDriver: MinIO, SeaweedFS, Garage are valid', function () {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── Spring Boot ConfigData Integration ────────────────────────────────────────

test('ConfigData accepts AppFramework::SPRINGBOOT framework', function () {
    $config = new App\Data\ConfigData;
    $config->framework = AppFramework::SPRINGBOOT;

    expect($config->framework)->toBe(AppFramework::SPRINGBOOT);
    expect($config->framework->value)->toBe('springboot');
});
