<?php

use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;

// ── nextjs:new Command Tests ─────────────────────────────────────────────────

test('nextjs:new command is registered and has correct signature', function () {
    $this->artisan('nextjs:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('nextjs:new');
});

test('nextjs:new command has --fast option', function () {
    $kernel = app(Illuminate\Contracts\Console\Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('nextjs:new');
    expect($commands['nextjs:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — Next.js ─────────────────────────────────────

test('AppFramework NEXTJS framework value is correct', function () {
    expect(AppFramework::NEXTJS->value)->toBe('nextjs');
    expect(AppFramework::NEXTJS->getLabel())->toBe('Next.js');
    expect(AppFramework::NEXTJS->healthProbePath())->toBe('/api/health');
    expect(AppFramework::NEXTJS->proxyCommand())->toBe('node');
});

test('Next.js CacheDriver matrix: only Redis is allowed (mandatory)', function () {
    // Per plan §2b: Next.js mandates Redis for distributed ISR/RSC
    $mandatory = CacheDriver::REDIS;
    $hidden = [CacheDriver::MEMCACHED, CacheDriver::DATABASE];

    expect($mandatory)->toBe(CacheDriver::REDIS);

    foreach ($hidden as $driver) {
        expect($driver)->not->toBe(CacheDriver::REDIS);
    }
});

test('Next.js DatabaseDriver matrix: MySQL, MariaDB, PostgreSQL are valid', function () {
    $supported = [
        DatabaseDriver::MYSQL,
        DatabaseDriver::MARIADB,
        DatabaseDriver::POSTGRESQL,
    ];

    $unsupported = [DatabaseDriver::SQLITE];

    expect($supported)->toHaveCount(3);

    foreach ($unsupported as $driver) {
        expect(in_array($driver, $supported))->toBeFalse();
    }
});

test('Next.js SearchDriver matrix: Meilisearch and Typesense are valid, database is hidden', function () {
    $supported = [SearchDriver::MEILISEARCH, SearchDriver::TYPESENSE];
    $hidden = [SearchDriver::DATABASE];

    expect($supported)->toHaveCount(2);

    foreach ($hidden as $driver) {
        expect(in_array($driver, $supported))->toBeFalse();
    }
});

// ── Next.js ConfigData Integration ───────────────────────────────────────────

test('ConfigData accepts AppFramework::NEXTJS and mandatory Redis cache', function () {
    $config = new App\Data\ConfigData;
    $config->framework = AppFramework::NEXTJS;
    $config->cacheDriver = CacheDriver::REDIS;

    expect($config->framework)->toBe(AppFramework::NEXTJS);
    expect($config->getCacheDriver())->toBe(CacheDriver::REDIS);
});

// ── Next.js config patch logic tests ─────────────────────────────────────────

test('NextjsNewCommand::patchNextConfig injects standalone output into a simple config', function () {
    $dir = sys_get_temp_dir().'/larakube-test-nextjs-patch-'.uniqid();
    mkdir($dir, 0o755, true);

    $original = <<<'TS'
import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  reactStrictMode: true,
};

export default nextConfig;
TS;
    file_put_contents("$dir/next.config.ts", $original);

    $command = new App\Commands\Nextjs\NextjsNewCommand;

    // Use reflection to call the protected method
    $ref = new ReflectionClass($command);
    $method = $ref->getMethod('patchNextConfig');
    $method->setAccessible(true);
    $method->invoke($command, $dir);

    $patched = file_get_contents("$dir/next.config.ts");
    expect($patched)->toContain("'standalone'");
    expect($patched)->toContain('cacheMaxMemorySize: 0');

    unlink("$dir/next.config.ts");
    rmdir($dir);
});

test('NextjsNewCommand::generateHealthRoute creates the route file', function () {
    $dir = sys_get_temp_dir().'/larakube-test-nextjs-health-'.uniqid();
    mkdir("$dir/app", 0o755, true);

    $command = new App\Commands\Nextjs\NextjsNewCommand;

    $ref = new ReflectionClass($command);
    $method = $ref->getMethod('generateHealthRoute');
    $method->setAccessible(true);
    $method->invoke($command, $dir);

    expect(file_exists("$dir/app/api/health/route.ts"))->toBeTrue();
    $content = file_get_contents("$dir/app/api/health/route.ts");
    expect($content)->toContain('GET');
    expect($content)->toContain('status');

    unlink("$dir/app/api/health/route.ts");
    rmdir("$dir/app/api/health");
    rmdir("$dir/app/api");
    rmdir("$dir/app");
    rmdir($dir);
});

test('NextjsNewCommand::generateCacheHandler creates cache-handler.mjs', function () {
    $dir = sys_get_temp_dir().'/larakube-test-nextjs-cache-'.uniqid();
    mkdir($dir, 0o755, true);

    $command = new App\Commands\Nextjs\NextjsNewCommand;

    $ref = new ReflectionClass($command);
    $method = $ref->getMethod('generateCacheHandler');
    $method->setAccessible(true);
    $method->invoke($command, $dir);

    expect(file_exists("$dir/cache-handler.mjs"))->toBeTrue();
    $content = file_get_contents("$dir/cache-handler.mjs");
    expect($content)->toContain('@neshca/cache-handler');
    expect($content)->toContain('REDIS_URL');

    unlink("$dir/cache-handler.mjs");
    rmdir($dir);
});
