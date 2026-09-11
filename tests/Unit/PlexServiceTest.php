<?php

/** PlexService's pure helpers, called directly on the object. */

use App\Data\ConfigData;
use App\Services\PlexService;

test('the Commons namespace is a constant, not configuration', function (): void {
    expect(PlexService::NAMESPACE)->toBe('larakube-plex');
});

test('tenant identifiers are safe SQL identifiers', function (): void {
    $plex = new PlexService;

    expect($plex->plexTenantIdentifier('app-one'))->toBe('app_one')
        ->and($plex->plexTenantIdentifier('My App!'))->toBe('my_app')
        ->and($plex->plexTenantIdentifier('  Drift.Labs  '))->toBe('drift_labs')
        // SQL identifiers must start with a letter.
        ->and($plex->plexTenantIdentifier('1app'))->toBe('app_1app')
        ->and($plex->plexTenantIdentifier(''))->toBe('app_');
});

test('non-production environments get their own suffixed tenant', function (): void {
    $plex = new PlexService;

    // Production stays un-suffixed for backwards compatibility with the
    // single-env Plex setups that predate per-env tenants.
    expect($plex->plexTenantIdentifier('app-one', 'production'))->toBe('app_one')
        ->and($plex->plexTenantIdentifier('app-one', 'local'))->toBe('app_one_local')
        ->and($plex->plexTenantIdentifier('app-one', 'staging'))->toBe('app_one_staging');
});

test('tenant identifiers stay inside Postgres 63-character cap', function (): void {
    $plex = new PlexService;
    $long = str_repeat('a', 100);

    expect($plex->plexTenantIdentifier($long))->toHaveLength(63)
        ->and($plex->plexTenantIdentifier($long, 'staging'))->toHaveLength(63)
        ->and($plex->plexTenantIdentifier($long, 'staging'))->toEndWith('_staging');
});

test('bucket names are DNS-safe, since S3 rejects the underscores tenants use', function (): void {
    $plex = new PlexService;

    expect($plex->plexBucketName('app_five'))->toBe('app-five')
        ->and($plex->plexBucketName('APP__Five__'))->toBe('app-five')
        // S3 requires at least three characters.
        ->and($plex->plexBucketName('a'))->toBe('lk-a')
        ->and($plex->plexBucketName(str_repeat('b', 100)))->toHaveLength(63);
});

test('a project contributes only the Commons services its drivers map to', function (): void {
    $plex = new PlexService;

    $config = ConfigData::from([
        'name' => 'demo',
        'database' => 'postgres',
        'cacheDriver' => 'redis',
        'objectStorage' => 'seaweedfs',
        'scoutDriver' => 'meilisearch',
        'environments' => ['local' => []],
    ]);

    expect($plex->projectCommonsServices($config))
        ->toContain('postgres', 'redis', 'seaweedfs', 'meilisearch');
});

test('SQLite contributes nothing shareable to the Commons', function (): void {
    $plex = new PlexService;

    $config = ConfigData::from([
        'name' => 'demo',
        'database' => 'sqlite',
        'environments' => ['local' => []],
    ]);

    expect($plex->projectCommonsServices($config))->not->toContain('sqlite');
});

test('the tenant SQL round-trips through create and drop', function (): void {
    $plex = new PlexService;

    expect($plex->buildPostgresTenantSql('app_one', 'app_one', 's3cret'))
        ->toContain('app_one')
        ->and($plex->buildDropTenantSql('app_one', 'app_one'))
        ->toContain('DROP DATABASE')
        ->toContain('app_one');
});

test('the context is accepted but irrelevant to the pure helpers', function (): void {
    // Pure helpers must not depend on which cluster the service targets.
    $local = new PlexService;
    $cloud = new PlexService('larakube-203-0-113-1');

    expect($cloud->plexTenantIdentifier('app-one', 'staging'))
        ->toBe($local->plexTenantIdentifier('app-one', 'staging'))
        ->and($cloud->plexBucketName('app_one'))->toBe($local->plexBucketName('app_one'));
});
