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
