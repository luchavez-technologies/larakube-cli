<?php

use App\Commands\Cloud\CloudExternalizeCommand;
use App\Data\ConfigData;
use App\Enums\CacheDriver;

function externalizer(): CloudExternalizeCommand
{
    return app(CloudExternalizeCommand::class);
}

function extConfig(): ConfigData
{
    return new ConfigData(name: 'ext-test');
}

test('Redis flips session/cache/queue to redis and the disk to s3 — without writing connection vars', function (): void {
    $values = externalizer()->externalizedEnvValues(CacheDriver::REDIS, hasS3: true, config: extConfig());

    expect($values)->toMatchArray([
        'SESSION_DRIVER' => 'redis',
        'CACHE_STORE' => 'redis',
        'QUEUE_CONNECTION' => 'redis',
        'FILESYSTEM_DISK' => 's3',
    ])
        // Connection vars are owned by add/plex:join — never written here.
        ->and($values)->not->toHaveKey('REDIS_HOST')
        ->and($values)->not->toHaveKey('REDIS_PORT');
});

test('Memcached flips session/cache to memcached and forces no queue connection', function (): void {
    $values = externalizer()->externalizedEnvValues(CacheDriver::MEMCACHED, hasS3: true, config: extConfig());

    expect($values)->toMatchArray([
        'SESSION_DRIVER' => 'memcached',
        'CACHE_STORE' => 'memcached',
        'FILESYSTEM_DISK' => 's3',
    ])
        ->and($values)->not->toHaveKey('QUEUE_CONNECTION')
        ->and($values)->not->toHaveKey('MEMCACHED_HOST');
});

test('Database reuses the DB and does not force a queue connection', function (): void {
    $values = externalizer()->externalizedEnvValues(CacheDriver::DATABASE, hasS3: true, config: extConfig());

    expect($values)->toMatchArray([
        'SESSION_DRIVER' => 'database',
        'CACHE_STORE' => 'database',
        'FILESYSTEM_DISK' => 's3',
    ])->and($values)->not->toHaveKey('QUEUE_CONNECTION');
});

test('the Database cache option is offered only when the primary DB is networked, not SQLite', function (): void {
    expect(externalizer()->offerableCacheDrivers(dbIsExternal: true))
        ->toContain(CacheDriver::DATABASE)
        ->toContain(CacheDriver::REDIS)
        ->and(externalizer()->offerableCacheDrivers(dbIsExternal: false))->not->toContain(CacheDriver::DATABASE)
        ->toContain(CacheDriver::REDIS)
        ->toContain(CacheDriver::MEMCACHED);
});

test('backendsPresent detects a backend from Plex membership OR a self-hosted/declared service', function (): void {
    // Self-hosted: declares MinIO + a Redis cache, not on a Commons.
    $selfHosted = ConfigData::from([
        'name' => 'ext-test',
        'objectStorage' => 'minio',
        'cacheDriver' => 'redis',
        'database' => 'mysql',
        'environments' => ['production' => ['plex' => []]],
    ]);
    expect(externalizer()->backendsPresent($selfHosted, 'production', CacheDriver::REDIS))->toBe([true, true]);
    // Memcached is neither declared nor on a Commons → not ready (would prompt).
    expect(externalizer()->backendsPresent($selfHosted, 'production', CacheDriver::MEMCACHED))->toBe([true, false]);

    // Plex member: redis + minio supplied by the Commons (no local declaration).
    $plexMember = ConfigData::from([
        'name' => 'ext-test',
        'database' => 'mysql',
        'environments' => ['production' => ['plex' => ['redis', 'minio']]],
    ]);
    expect(externalizer()->backendsPresent($plexMember, 'production', CacheDriver::REDIS))->toBe([true, true]);

    // Nothing wired → both missing (the VPS→multi-node first-timer).
    $bare = ConfigData::from(['name' => 'ext-test', 'database' => 'mysql', 'environments' => ['production' => []]]);
    expect(externalizer()->backendsPresent($bare, 'production', CacheDriver::REDIS))->toBe([false, false]);
});

test('without object storage it leaves FILESYSTEM_DISK alone', function (): void {
    expect(externalizer()->externalizedEnvValues(CacheDriver::DATABASE, hasS3: false, config: extConfig()))
        ->not->toHaveKey('FILESYSTEM_DISK');
});

test('applying the flips externalizes the drivers without clobbering Commons-owned keys', function (): void {
    $env = implode("\n", [
        'FILESYSTEM_DISK=local',
        'SESSION_DRIVER=file',
        'CACHE_STORE=file',
        'AWS_BUCKET=myapp-production',
        'AWS_ACCESS_KEY_ID=PLEXKEY',
        'REDIS_HOST=redis.larakube-plex.svc.cluster.local',
    ]);

    $values = externalizer()->externalizedEnvValues(CacheDriver::REDIS, hasS3: true, config: extConfig());
    $out = externalizer()->applyEnvValues($env, $values);

    expect($out)
        ->toContain('FILESYSTEM_DISK=s3')
        ->toContain('SESSION_DRIVER=redis')
        ->toContain('CACHE_STORE=redis')
        ->toContain('QUEUE_CONNECTION=redis')
        // Commons-owned connection values are untouched.
        ->toContain('AWS_BUCKET=myapp-production')
        ->toContain('AWS_ACCESS_KEY_ID=PLEXKEY')
        ->toContain('REDIS_HOST=redis.larakube-plex.svc.cluster.local')
        // No duplicate FILESYSTEM_DISK line (replaced in place, not appended).
        ->and(substr_count($out, 'FILESYSTEM_DISK='))->toBe(1);
});
