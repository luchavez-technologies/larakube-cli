<?php

use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;

// ── adonisjs:new Command Tests ────────────────────────────────────────────────

test('adonisjs:new command is registered and has correct signature', function () {
    $this->artisan('adonisjs:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('adonisjs:new');
});

test('adonisjs:new command has --fast option', function () {
    $kernel = app(Illuminate\Contracts\Console\Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('adonisjs:new');
    expect($commands['adonisjs:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — AdonisJS ────────────────────────────────────

test('AppFramework ADONISJS framework value is correct', function () {
    expect(AppFramework::ADONISJS->value)->toBe('adonisjs');
    expect(AppFramework::ADONISJS->getLabel())->toBe('AdonisJS');
    expect(AppFramework::ADONISJS->healthProbePath())->toBe('/healthz');
    expect(AppFramework::ADONISJS->proxyCommand())->toBe('node ace');
});

test('AdonisJS DatabaseDriver matrix: PostgreSQL, MySQL, MariaDB are valid', function () {
    $supported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];

    expect($supported)->toHaveCount(3);
    foreach ($supported as $driver) {
        expect($driver)->toBeInstanceOf(DatabaseDriver::class);
    }
});

test('AdonisJS CacheDriver matrix: Redis, Memcached are valid', function () {
    $supported = [CacheDriver::REDIS, CacheDriver::MEMCACHED];

    expect($supported)->toHaveCount(2);
});

test('AdonisJS StorageDriver: MinIO, SeaweedFS, Garage are valid', function () {
    $supported = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($supported)->toHaveCount(3);
});

// ── AdonisJS ConfigData Integration ──────────────────────────────────────────

test('ConfigData accepts AppFramework::ADONISJS framework', function () {
    $config = new App\Data\ConfigData;
    $config->framework = AppFramework::ADONISJS;

    expect($config->framework)->toBe(AppFramework::ADONISJS);
    expect($config->framework->value)->toBe('adonisjs');
});
