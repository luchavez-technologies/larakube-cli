<?php

/**
 * Guards config LOADING — the path that, when it throws, gets swallowed into a
 * null and surfaces later as "Call to getAllHosts() on null" on `up`/`hosts`.
 * Covers current + legacy blueprint shapes so a schema change can't silently
 * break loading of existing projects.
 */

use App\Data\ConfigData;
use App\Traits\InteractsWithHosts;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('a current-shape blueprint loads and resolves hosts', function (): void {
    $config = ConfigData::from([
        'name' => 'test2',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.4',
        'database' => 'sqlite',
        'environments' => ['local' => [], 'production' => []],
    ]);

    expect($config->getName())->toBe('test2')
        ->and($config->getAllHosts('local'))->toBeArray();
});

test('a legacy blueprint with the removed productionImage key still loads', function (): void {
    $config = ConfigData::from([
        'name' => 'legacy',
        'productionImage' => 'ghcr.io/team/legacy',
        'environments' => ['local' => [], 'production' => []],
    ]);

    expect($config->getName())->toBe('legacy')
        ->and($config->getAllHosts('local'))->toBeArray();
});

test('a legacy blueprint with a top-level cloud map still loads', function (): void {
    $config = ConfigData::from([
        'name' => 'legacy-cloud',
        'environments' => ['local' => [], 'production' => []],
        'cloud' => [
            'production' => ['ip' => '203.0.113.10', 'user' => 'deploy', 'port' => 22, 'key' => '/k'],
            'users' => [['username' => 'alice', 'authorized_keys' => [['public_key' => 'ssh-ed25519 AAAA']]]],
        ],
    ]);

    expect($config->getCloudIp('production'))->toBe('203.0.113.10')
        ->and($config->getAllHosts('local'))->toBeArray();
});

test('loadFromFile reads a written blueprint and resolves hosts', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    file_put_contents($dir.'/'.ConfigData::CONFIG_FILE, json_encode([
        'name' => 'fromfile',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.4',
        'database' => 'sqlite',
        'environments' => ['local' => [], 'production' => []],
    ]));

    $config = ConfigData::loadFromFile($dir);
    expect($config->getName())->toBe('fromfile')
        ->and($config->getAllHosts('local'))->toBeArray();

    $temporaryDirectory->delete();
});

test('ensureHostsAreSet does not crash when the project config is missing/unreadable', function (): void {
    // Regression for "Call to getAllHosts() on null" on `up`/`hosts` when
    // .larakube.json can't be loaded: the host step must skip, not fatal.
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();

    $previous = getcwd();
    chdir($dir);

    try {
        $runner = new class
        {
            use InteractsWithHosts;

            public function run(): void
            {
                $this->ensureHostsAreSet();
            }
        };

        // No .larakube.json here → config is null → must return cleanly (no throw).
        expect(fn () => $runner->run())->not->toThrow(Throwable::class);
    } finally {
        chdir($previous);
        $temporaryDirectory->delete();
    }
});

test('cloudflareToken persists only to the gitignored local file and wins on load', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();

    $config = ConfigData::from([
        'name' => 'cf-test',
        'database' => 'sqlite',
        'environments' => ['local' => [], 'production' => []],
    ]);
    $config->setCloudflareToken('cf-project-token-123');
    $config->saveToFile($dir);

    // Committed blueprint must NOT contain the secret; local file must.
    $committed = json_decode((string) file_get_contents("$dir/.larakube.json"), true);
    $local = json_decode((string) file_get_contents("$dir/.larakube.local.json"), true);
    expect($committed)->not->toHaveKey('cloudflareToken')
        ->and($local['cloudflareToken'])->toBe('cf-project-token-123');

    // Reload resolves the token from the local file.
    $reloaded = ConfigData::loadFromFile($dir);
    expect($reloaded->getCloudflareToken())->toBe('cf-project-token-123');

    $temporaryDirectory->delete();
});
