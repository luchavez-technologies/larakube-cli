<?php

use App\Commands\Nextjs\NextjsNewCommand;
use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use Illuminate\Contracts\Console\Kernel;
use Spatie\TemporaryDirectory\TemporaryDirectory;

// ── nextjs:new Command Tests ─────────────────────────────────────────────────

test('nextjs:new command is registered and has correct signature', function (): void {
    $this->artisan('nextjs:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('nextjs:new');
});

test('nextjs:new command has --fast option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('nextjs:new')
        ->and($commands['nextjs:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — Next.js ─────────────────────────────────────

test('AppFramework NEXTJS framework value is correct', function (): void {
    expect(AppFramework::NEXTJS->value)->toBe('nextjs')
        ->and(AppFramework::NEXTJS->getLabel())->toBe('Next.js')
        ->and(AppFramework::NEXTJS->healthProbePath())->toBe('/api/health')
        ->and(AppFramework::NEXTJS->proxyCommand())->toBe('node');
});

test('Next.js CacheDriver matrix: only Redis is allowed (mandatory)', function (): void {
    // Per plan §2b: Next.js mandates Redis for distributed ISR/RSC
    $mandatory = CacheDriver::REDIS;
    $hidden = [CacheDriver::MEMCACHED, CacheDriver::DATABASE];

    expect($mandatory)->toBe(CacheDriver::REDIS)->and($hidden)->each->not->toBe(CacheDriver::REDIS);
});

test('Next.js DatabaseDriver matrix: MySQL, MariaDB, PostgreSQL are valid', function (): void {
    $supported = [
        DatabaseDriver::MYSQL,
        DatabaseDriver::MARIADB,
        DatabaseDriver::POSTGRESQL,
    ];

    $unsupported = [DatabaseDriver::SQLITE];

    expect($supported)->toHaveCount(3);

    foreach ($unsupported as $driver) {
        expect($supported)->not->toContain($driver);
    }
});

test('Next.js SearchDriver matrix: Meilisearch and Typesense are valid, database is hidden', function (): void {
    $supported = [SearchDriver::MEILISEARCH, SearchDriver::TYPESENSE];
    $hidden = [SearchDriver::DATABASE];

    expect($supported)->toHaveCount(2);

    foreach ($hidden as $driver) {
        expect($supported)->not->toContain($driver);
    }
});

// ── Next.js ConfigData Integration ───────────────────────────────────────────

test('ConfigData accepts AppFramework::NEXTJS and mandatory Redis cache', function (): void {
    $config = new ConfigData;
    $config->framework = AppFramework::NEXTJS;
    $config->cacheDriver = CacheDriver::REDIS;

    expect($config->framework)->toBe(AppFramework::NEXTJS)
        ->and($config->getCacheDriver())->toBe(CacheDriver::REDIS);
});

// ── Next.js config patch logic tests ─────────────────────────────────────────

test('NextjsNewCommand::patchNextConfig injects standalone output into a simple config', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();

    $original = <<<'TS'
import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  reactStrictMode: true,
};

export default nextConfig;
TS;
    file_put_contents("$dir/next.config.ts", $original);

    $command = new NextjsNewCommand;

    // Use reflection to call the protected method
    $ref = new ReflectionClass($command);
    $method = $ref->getMethod('patchNextConfig');
    $method->setAccessible(true);
    $method->invoke($command, $dir);

    $patched = file_get_contents("$dir/next.config.ts");
    expect($patched)->toContain("'standalone'")
        ->toContain('cacheMaxMemorySize: 0');

    $temporaryDirectory->delete();
});

test('NextjsNewCommand::generateHealthRoute creates the route file', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    mkdir("$dir/app", 0o755, true);

    $command = new NextjsNewCommand;

    $ref = new ReflectionClass($command);
    $method = $ref->getMethod('generateHealthRoute');
    $method->setAccessible(true);
    $method->invoke($command, $dir);

    expect(file_exists("$dir/app/api/health/route.ts"))->toBeTrue();
    $content = file_get_contents("$dir/app/api/health/route.ts");
    expect($content)->toContain('GET')
        ->toContain('status');

    $temporaryDirectory->delete();
});

test('NextjsNewCommand::generateCacheHandler creates cache-handler.mjs', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();

    $command = new NextjsNewCommand;

    $ref = new ReflectionClass($command);
    $method = $ref->getMethod('generateCacheHandler');
    $method->setAccessible(true);
    $method->invoke($command, $dir);

    expect(file_exists("$dir/cache-handler.mjs"))->toBeTrue();
    $content = file_get_contents("$dir/cache-handler.mjs");
    expect($content)->toContain('@neshca/cache-handler')
        ->toContain('REDIS_URL');

    $temporaryDirectory->delete();
});
