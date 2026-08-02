<?php

use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;

// ── wordpress:new Command Tests ──────────────────────────────────────────────

test('wordpress:new command is registered and has correct signature', function () {
    $this->artisan('wordpress:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('wordpress:new');
});

test('wordpress:new command has --fast option', function () {
    $kernel = app(Illuminate\Contracts\Console\Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('wordpress:new');
    expect($commands['wordpress:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — WordPress ───────────────────────────────────

test('AppFramework WORDPRESS framework value is correct', function () {
    expect(AppFramework::WORDPRESS->value)->toBe('wordpress');
    expect(AppFramework::WORDPRESS->getLabel())->toBe('WordPress (Bedrock)');
    expect(AppFramework::WORDPRESS->healthProbePath())->toBe('/wp-includes/version.php');
});

test('WordPress DatabaseDriver matrix: only MySQL and MariaDB are valid', function () {
    // Drivers that WordPress officially supports
    $supported = [DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];
    $unsupported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::SQLITE];

    foreach ($supported as $driver) {
        expect(in_array($driver, $supported))->toBeTrue("Expected $driver->value to be supported by WordPress");
    }

    foreach ($unsupported as $driver) {
        expect(in_array($driver, $supported))->toBeFalse("Expected $driver->value to be unsupported by WordPress");
    }
});

test('WordPress CacheDriver matrix: database driver is hidden', function () {
    // Per plan §2b: WordPress cannot use database CacheDriver (WP transients use wp_options)
    $allowed = [CacheDriver::REDIS, CacheDriver::MEMCACHED];
    $hidden = [CacheDriver::DATABASE];

    foreach ($allowed as $driver) {
        expect(in_array($driver, $allowed))->toBeTrue();
    }

    foreach ($hidden as $driver) {
        expect(in_array($driver, $hidden))->toBeTrue();
    }
});

test('WordPress StorageDriver: all S3-compatible drivers are valid (mandatory)', function () {
    $mandatory = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($mandatory)->toHaveCount(3);
    foreach ($mandatory as $driver) {
        expect($driver)->toBeInstanceOf(StorageDriver::class);
    }
});

// ── WordPress ConfigData Integration ─────────────────────────────────────────

test('ConfigData accepts AppFramework::WORDPRESS framework', function () {
    $config = new App\Data\ConfigData;
    $config->framework = AppFramework::WORDPRESS;

    expect($config->framework)->toBe(AppFramework::WORDPRESS);
    expect($config->framework->value)->toBe('wordpress');
});
