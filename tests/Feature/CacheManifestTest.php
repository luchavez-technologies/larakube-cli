<?php

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\ServerVariation;

test('Cache: Nginx + SQLite + Redis', function (): void {
    $config = new ConfigData(name: 'redis-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setCacheDriver(CacheDriver::REDIS);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Cache: Nginx + SQLite + Memcached', function (): void {
    $config = new ConfigData(name: 'memcached-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setCacheDriver(CacheDriver::MEMCACHED);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('per-project Redis uses TCP probes with room for a busy node, like the Commons Redis', function (): void {
    $config = new ConfigData(name: 'redis-probes');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setCacheDriver(CacheDriver::REDIS);

    expect(generateManifests($config))
        ->not->toContain('command: ["redis-cli", "ping"]')
        ->toContain("tcpSocket:\n              port: 6379")
        ->toContain('timeoutSeconds: 3')
        ->toContain('failureThreshold: 6');
});
