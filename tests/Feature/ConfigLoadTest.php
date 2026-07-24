<?php

/**
 * Guards config LOADING — the path that, when it throws, gets swallowed into a
 * null and surfaces later as "Call to getAllHosts() on null" on `up`/`hosts`.
 * Covers current + legacy blueprint shapes so a schema change can't silently
 * break loading of existing projects.
 */

use App\Data\ConfigData;
use App\Traits\InteractsWithHosts;

test('a current-shape blueprint loads and resolves hosts', function () {
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

test('a legacy blueprint with the removed productionImage key still loads', function () {
    $config = ConfigData::from([
        'name' => 'legacy',
        'productionImage' => 'ghcr.io/team/legacy',
        'environments' => ['local' => [], 'production' => []],
    ]);

    expect($config->getName())->toBe('legacy')
        ->and($config->getAllHosts('local'))->toBeArray();
});

test('a legacy blueprint with a top-level cloud map still loads', function () {
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

test('loadFromFile reads a written blueprint and resolves hosts', function () {
    $dir = sys_get_temp_dir().'/lk-cfgload-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);
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

    @unlink($dir.'/'.ConfigData::CONFIG_FILE);
    @rmdir($dir);
});

test('ensureHostsAreSet does not crash when the project config is missing/unreadable', function () {
    // Regression for "Call to getAllHosts() on null" on `up`/`hosts` when
    // .larakube.json can't be loaded: the host step must skip, not fatal.
    $dir = sys_get_temp_dir().'/lk-nohost-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

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
        @rmdir($dir);
    }
});

test('cloudflareToken persists only to the gitignored local file and wins on load', function () {
    $dir = sys_get_temp_dir().'/larakube-cftoken-'.uniqid();
    mkdir($dir, 0755, true);

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
    expect($committed)->not->toHaveKey('cloudflareToken');
    expect($local['cloudflareToken'])->toBe('cf-project-token-123');

    // Reload resolves the token from the local file.
    $reloaded = ConfigData::loadFromFile($dir);
    expect($reloaded->getCloudflareToken())->toBe('cf-project-token-123');

    exec('rm -rf '.escapeshellarg($dir));
});
