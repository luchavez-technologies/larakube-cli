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

test('the default spec enables only Postgres + Redis', function (): void {
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

test('Commons service images/ports derive from the driver enums (no drift)', function (): void {
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

test('enabling Meilisearch in the spec turns it on', function (): void {
    $p = plexSpec();

    // No --with-meili flag — Meili is just another service you enable.
    $spec = $p->normalizeCommonsSpec(['services' => ['meilisearch' => ['enabled' => true]]]);

    expect($p->enabledCommonsServices($spec))->toBe(['postgres', 'redis', 'meilisearch']);
});

test('normalize fills defaults for a partial spec and respects an explicit disable', function (): void {
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

test('normalize is idempotent so export → init --from is lossless', function (): void {
    $p = plexSpec();

    $once = $p->normalizeCommonsSpec(['services' => ['meilisearch' => ['enabled' => true], 'seaweedfs' => ['enabled' => true]]]);

    expect($p->normalizeCommonsSpec($once))->toEqual($once);
});

test('the pooler sub-key defaults off for Postgres and is absent from non-pooling services', function (): void {
    $spec = plexSpec()->defaultCommonsSpec()['services'];

    expect($spec['postgres']['pooler'])->toBe([
        'enabled' => false,
        'mode' => 'transaction',
        'poolSize' => 20,
        'maxClients' => 400,
    ])
        // Only Postgres is wired in phase 1 (see the plan) — MySQL/MariaDB's
        // ProxySQL pooler doesn't exist yet, so DatabaseDriver::supportsPooling()
        // is false for them and the normalizer must not invent the sub-key.
        ->and($spec['mysql'])->not->toHaveKey('pooler')
        ->and($spec['mariadb'])->not->toHaveKey('pooler')
        // Redis/Meili/S3-alikes aren't DatabaseDriver cases at all — no pooler
        // concept applies, so normalize must not invent the key for them.
        ->and($spec['redis'])->not->toHaveKey('pooler')
        ->and($spec['meilisearch'])->not->toHaveKey('pooler')
        ->and($spec['seaweedfs'])->not->toHaveKey('pooler')
        ->and($spec['minio'])->not->toHaveKey('pooler')
        ->and($spec['garage'])->not->toHaveKey('pooler');
});

test('turning the pooler on in a partial spec is preserved, not defaulted back off', function (): void {
    $p = plexSpec();

    $spec = $p->normalizeCommonsSpec([
        'services' => ['postgres' => ['pooler' => ['enabled' => true, 'poolSize' => 50]]],
    ]);

    expect($spec['services']['postgres']['pooler']['enabled'])->toBeTrue()
        ->and($spec['services']['postgres']['pooler']['poolSize'])->toBe(50)
        ->and($spec['services']['postgres']['pooler']['maxClients'])->toBe(400); // untouched default fills in

    // Idempotent, same as the rest of the spec.
    expect($p->normalizeCommonsSpec($spec))->toEqual($spec);
});

test('every DatabaseDriver case answers the pooler methods so a new engine cannot silently skip them', function (): void {
    foreach (DatabaseDriver::cases() as $driver) {
        expect($driver->supportsPooling())->toBeBool();

        if ($driver->supportsPooling()) {
            expect($driver->poolerImage())->toBeString()->not->toBe('')
                ->and($driver->poolerPort())->toBeGreaterThan(0)
                ->and($driver->poolerPrimaryServiceName())->toBeString()->not->toBe('');
        } else {
            expect($driver->poolerImage())->toBeNull()
                ->and($driver->poolerPort())->toBe(0)
                ->and($driver->poolerPrimaryServiceName())->toBeNull();
        }
    }
});

test('Postgres pooler image is pinned, never floating', function (): void {
    expect(DatabaseDriver::POSTGRESQL->poolerImage())
        ->toContain(':')
        ->not->toEndWith(':latest');
});
