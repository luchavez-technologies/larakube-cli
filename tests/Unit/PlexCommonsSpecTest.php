<?php

/**
 * Pure-logic tests for the Plex Commons spec — the shape that drives the
 * manifest renderer and survives the plex:export → plex:init --from round-trip.
 * The kubectl-touching parts of InteractsWithPlex belong in a cluster smoke test,
 * not here.
 */

use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithPlex;

function plexSpec(): object
{
    return new class
    {
        use InteractsWithPlex;
    };
}

test('the default spec enables only Postgres + Redis', function () {
    $p = plexSpec();
    $spec = $p->defaultCommonsSpec();

    expect($p->enabledCommonsServices($spec))->toBe(['postgres', 'redis'])
        ->and($spec['services']['postgres']['image'])->toBe('postgres:17.9')
        ->and($spec['services']['postgres']['storage'])->toBe('10Gi')
        ->and($spec['services']['postgres']['memory'])->toBe('1Gi')   // shared-DB ceiling, configurable
        ->and($spec['services']['redis']['port'])->toBe(6379)
        ->and($spec['services']['meilisearch']['enabled'])->toBeFalse()  // opt-in, off by default
        ->and($spec['services']['seaweedfs']['enabled'])->toBeFalse()
        ->and($spec['services']['minio']['enabled'])->toBeFalse()        // MinIO is an opt-in S3 backend (SeaweedFS alternative)
        ->and($spec['services']['mysql']['enabled'])->toBeFalse()        // MySQL/MariaDB are opt-in db backends
        ->and($spec['services']['mariadb']['enabled'])->toBeFalse();
});

test('Commons service images/ports derive from the driver enums (no drift)', function () {
    // Every service is present in the shape (enabled or not), so its image/port
    // is assertable regardless of the default.
    $spec = plexSpec()->defaultCommonsSpec()['services'];

    expect($spec['postgres']['image'])->toBe(DatabaseDriver::POSTGRESQL->getDockerImage())
        ->and($spec['postgres']['port'])->toBe(DatabaseDriver::POSTGRESQL->dbPort())
        ->and($spec['mysql']['image'])->toBe(DatabaseDriver::MYSQL->getDockerImage())
        ->and($spec['mysql']['port'])->toBe(DatabaseDriver::MYSQL->dbPort())
        ->and($spec['mariadb']['image'])->toBe(DatabaseDriver::MARIADB->getDockerImage())
        ->and($spec['mariadb']['port'])->toBe(DatabaseDriver::MARIADB->dbPort())
        ->and($spec['redis']['image'])->toBe(CacheDriver::REDIS->getDockerImage())
        ->and($spec['redis']['port'])->toBe(CacheDriver::REDIS->dbPort())
        ->and($spec['meilisearch']['image'])->toBe(SearchDriver::MEILISEARCH->getDockerImage())  // stays in lockstep, no stale literal
        ->and($spec['meilisearch']['port'])->toBe(SearchDriver::MEILISEARCH->port())
        ->and($spec['seaweedfs']['image'])->toBe(StorageDriver::SEAWEEDFS->getDockerImage())
        ->and($spec['seaweedfs']['port'])->toBe(StorageDriver::SEAWEEDFS->port())
        ->and($spec['minio']['image'])->toBe(StorageDriver::MINIO->getDockerImage())
        ->and($spec['minio']['port'])->toBe(StorageDriver::MINIO->port());
});

test('enabling Meilisearch in the spec turns it on', function () {
    $p = plexSpec();

    // No --with-meili flag — Meili is just another service you enable.
    $spec = $p->normalizeCommonsSpec(['services' => ['meilisearch' => ['enabled' => true]]]);

    expect($p->enabledCommonsServices($spec))->toBe(['postgres', 'redis', 'meilisearch']);
});

test('normalize fills defaults for a partial spec and respects an explicit disable', function () {
    $p = plexSpec();

    $spec = $p->normalizeCommonsSpec([
        'services' => [
            'postgres' => ['enabled' => false],   // explicitly off
            'redis' => ['storage' => 'ignored'],  // partial — defaults should fill image/port
        ],
    ]);

    expect($p->enabledCommonsServices($spec))->toBe(['redis'])
        ->and($spec['services']['postgres']['enabled'])->toBeFalse()
        ->and($spec['services']['redis']['image'])->toBe('valkey/valkey:8.0-alpine')   // default filled
        ->and($spec['services']['redis']['port'])->toBe(6379);
});

test('normalize is idempotent so export → init --from is lossless', function () {
    $p = plexSpec();

    $once = $p->normalizeCommonsSpec(['services' => ['meilisearch' => ['enabled' => true], 'seaweedfs' => ['enabled' => true]]]);

    expect($p->normalizeCommonsSpec($once))->toEqual($once);
});
