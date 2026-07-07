<?php

/**
 * The PlexProvisionable contract lets the plex commands ask the driver enums
 * "are you a Commons service, and are you wired up yet?" instead of hardcoding
 * 'postgres'/'redis'. The service name is just the driver's own value (no
 * remapping), so distinct backends stay distinct and can coexist.
 */

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\ScoutDriver;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithPlex;

function plexCatalog(): object
{
    return new class
    {
        use InteractsWithPlex;
    };
}

test('a Commons service name is just the driver value (no remapping)', function () {
    expect(DatabaseDriver::POSTGRESQL->commonsServiceName())->toBe('postgres')
        ->and(CacheDriver::REDIS->commonsServiceName())->toBe('redis')
        ->and(ScoutDriver::MEILISEARCH->commonsServiceName())->toBe('meilisearch')   // the enum value, not a shortened 'meili'
        ->and(StorageDriver::SEAWEEDFS->commonsServiceName())->toBe('seaweedfs')
        ->and(StorageDriver::MINIO->commonsServiceName())->toBe('minio');            // distinct from seaweedfs → can coexist
});

test('non-shareable drivers have no Commons service', function () {
    expect(DatabaseDriver::SQLITE->commonsServiceName())->toBeNull()   // local file
        ->and(CacheDriver::DATABASE->commonsServiceName())->toBeNull() // runs in the app's own DB
        ->and(ScoutDriver::DATABASE->commonsServiceName())->toBeNull();
});

test('plex-readiness reflects what is actually wired today', function () {
    expect(DatabaseDriver::POSTGRESQL->isPlexReady())->toBeTrue()
        ->and(DatabaseDriver::MYSQL->isPlexReady())->toBeTrue()         // wired Commons db backend
        ->and(DatabaseDriver::MARIADB->isPlexReady())->toBeTrue()       // wired Commons db backend
        ->and(DatabaseDriver::MONGODB->isPlexReady())->toBeFalse()      // mapped, not yet wired
        ->and(CacheDriver::REDIS->isPlexReady())->toBeTrue()
        ->and(ScoutDriver::MEILISEARCH->isPlexReady())->toBeTrue()
        ->and(ScoutDriver::TYPESENSE->isPlexReady())->toBeFalse()
        ->and(StorageDriver::SEAWEEDFS->isPlexReady())->toBeTrue()   // wired: Commons S3 backend
        ->and(StorageDriver::MINIO->isPlexReady())->toBeTrue()       // wired: SeaweedFS alternative
        ->and(StorageDriver::GARAGE->isPlexReady())->toBeTrue();     // wired: shared "commons-admin" key, not a root credential
});

test('the catalog lists every shareable service, including coexisting S3 backends', function () {
    $catalog = plexCatalog()->commonsServiceCatalog();

    // ready (wired today) services — both db engines + all three S3 backends
    expect($catalog['postgres']['ready'])->toBeTrue()
        ->and($catalog['mysql']['ready'])->toBeTrue()
        ->and($catalog['mariadb']['ready'])->toBeTrue()
        ->and($catalog['redis']['ready'])->toBeTrue()
        ->and($catalog['meilisearch']['ready'])->toBeTrue()
        ->and($catalog['seaweedfs']['ready'])->toBeTrue()
        ->and($catalog['minio']['ready'])->toBeTrue()
        ->and($catalog['garage']['ready'])->toBeTrue();

    // each S3 backend is its OWN entry (so they coexist), not collapsed into one.
    expect($catalog)->toHaveKeys(['seaweedfs', 'minio', 'garage']);

    // mapped-but-not-ready services are still listed
    expect($catalog['mongodb']['ready'])->toBeFalse()
        ->and($catalog['typesense']['ready'])->toBeFalse();

    // non-shareable drivers are excluded entirely
    expect($catalog)->not->toHaveKey('sqlite')
        ->and($catalog)->not->toHaveKey('database');
});

test('projectCommonsServices returns only the project\'s plex-ready services', function () {
    $config = new ConfigData(
        name: 'app-four',
        database: DatabaseDriver::POSTGRESQL,    // ready → included
        cacheDriver: CacheDriver::REDIS,         // ready → included
        objectStorage: StorageDriver::SEAWEEDFS, // ready (Commons S3 backend) → included
    );

    expect(plexCatalog()->projectCommonsServices($config))->toBe(['postgres', 'redis', 'seaweedfs']);

    // A project on the default (database) cache + no DB shares nothing.
    expect(plexCatalog()->projectCommonsServices(new ConfigData(name: 'plain')))->toBe([]);
});
