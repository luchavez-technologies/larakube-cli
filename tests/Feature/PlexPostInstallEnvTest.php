<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Every driver's onPostInstall() wrote its OWN connection env straight to .env,
 * bypassing the isPlexBacked() guard that only lives in ConfigData's env
 * roll-up. installComponents() runs from `up` ("Missing local dependencies"),
 * so a joined project had its Commons values reverted to project-namespace
 * FQDNs — pods the overlay delete-patches had already removed. Confirmed live
 * 2026-09-09 on hello-stat: RedisException, getaddrinfo for
 * redis.hello-stat-local.svc.cluster.local failed.
 */
function plexPostInstallConfig(array $plex): ConfigData
{
    $config = new ConfigData;
    $config->setName('hello-stat');
    $config->addEnvironment('local');
    $config->environments['local'] = new EnvironmentData(plex: $plex);

    return $config;
}

/** @return array{0: string, 1: TemporaryDirectory} */
function plexPostInstallProject(): array
{
    $dir = TemporaryDirectory::make();
    $path = $dir->path();
    file_put_contents($path.'/.env', "APP_NAME=Larakube\nDB_HOST=postgres.larakube-plex.svc.cluster.local\nREDIS_HOST=redis.larakube-plex.svc.cluster.local\n");

    return [$path, $dir];
}

test('a Commons-backed driver leaves the tenant .env alone', function (): void {
    [$path, $dir] = plexPostInstallProject();
    $config = plexPostInstallConfig(['postgres', 'redis']);

    DatabaseDriver::POSTGRESQL->onPostInstall($path, $config);
    CacheDriver::REDIS->onPostInstall($path, $config);

    $env = (string) file_get_contents($path.'/.env');

    expect($env)->toContain('DB_HOST=postgres.larakube-plex.svc.cluster.local')
        ->and($env)->toContain('REDIS_HOST=redis.larakube-plex.svc.cluster.local')
        ->and($env)->not->toContain('hello-stat-local.svc.cluster.local');

    $dir->delete();
});

test('a self-hosted driver still writes its own connection', function (): void {
    // --no-plex projects genuinely need these values written.
    [$path, $dir] = plexPostInstallProject();
    $config = plexPostInstallConfig([]);

    CacheDriver::REDIS->onPostInstall($path, $config);

    expect((string) file_get_contents($path.'/.env'))->toContain('REDIS_HOST=');

    $dir->delete();
});

test('search and storage drivers carry the same guard', function (): void {
    foreach ([
        'app/Enums/CacheDriver.php',
        'app/Enums/DatabaseDriver.php',
        'app/Enums/SearchDriver.php',
        'app/Enums/StorageDriver.php',
    ] as $file) {
        expect((string) file_get_contents(base_path($file)))
            ->toContain("isPlexBacked(\$this, 'local')");
    }
});
