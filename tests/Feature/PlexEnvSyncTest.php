<?php

/**
 * Durability guard for Plex tenants: a service marked `plex` in an env must be
 * EXCLUDED from env-sync's computed variables, so `plex:join`'s Commons
 * connection (written to .env) survives a `heal`/regenerate instead of being
 * clobbered back to in-namespace defaults. A normally-`managed` service (e.g.
 * RDS) still emits its connection, as before.
 */

use App\Data\ConfigData;

function plexEnvConfig(array $productionOverrides): ConfigData
{
    $config = ConfigData::from([
        'name' => 'tenant-app',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.4',
        'database' => 'postgres',
        'environments' => [
            'local' => [],
            'production' => array_merge(['hosts' => ['web' => 'tenant.example.com']], $productionOverrides),
        ],
    ]);
    $config->resolveDependencies();

    return $config;
}

test('a plex-backed postgres is omitted from env-sync (so heal cannot clobber it)', function (): void {
    $config = plexEnvConfig(['managed' => ['postgres'], 'plex' => ['postgres']]);

    $envs = $config->getAllEnvironmentVariables('production');

    expect($envs)
        ->not->toHaveKey('DB_HOST')        // owned by .env via plex:join — not recomputed
        ->not->toHaveKey('DB_DATABASE')
        ->not->toHaveKey('DB_USERNAME')
        ->not->toHaveKey('DB_PASSWORD');
});

test('a normally-managed (non-plex) postgres still emits its connection', function (): void {
    // managed (e.g. RDS) but NOT plex → env-sync should still compute DB_*.
    $config = plexEnvConfig(['managed' => ['postgres']]);

    expect($config->getAllEnvironmentVariables('production'))->toHaveKey('DB_HOST');
});

test('local is unaffected (no plex) — DB connection is still emitted', function (): void {
    $config = plexEnvConfig(['managed' => ['postgres'], 'plex' => ['postgres']]);

    expect($config->getAllEnvironmentVariables('local'))->toHaveKey('DB_HOST');
});

test('a managed/plex service is excluded from wait-for-deps (no in-namespace nc that never resolves)', function (): void {
    $config = plexEnvConfig(['managed' => ['postgres'], 'plex' => ['postgres']]);

    // Production: postgres is managed (external/Commons) → not waited on.
    $prodDeps = array_map(fn ($d) => $d->value, $config->getCoreDependencies('production'));
    expect($prodDeps)->not->toContain('postgres');

    // Local: it's a real in-namespace pod → still waited on.
    $localDeps = array_map(fn ($d) => $d->value, $config->getCoreDependencies('local'));
    expect($localDeps)->toContain('postgres');
});
