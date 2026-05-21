<?php

use App\Enums\CacheDriver;

test('cache driver has correct labels', function () {
    expect(CacheDriver::REDIS->getLabel())->toBe('Redis')
        ->and(CacheDriver::MEMCACHED->getLabel())->toBe('Memcached')
        ->and(CacheDriver::DATABASE->getLabel())->toBe('Database (uses your primary DB)');
});

test('cache driver has correct ports', function () {
    expect(CacheDriver::REDIS->dbPort())->toBe(6379)
        ->and(CacheDriver::MEMCACHED->dbPort())->toBe(11211)
        ->and(CacheDriver::DATABASE->dbPort())->toBe(0);
});

test('cache driver has correct pod names', function () {
    expect(CacheDriver::REDIS->getPodName())->toBe('redis')
        ->and(CacheDriver::MEMCACHED->getPodName())->toBe('memcached')
        ->and(CacheDriver::DATABASE->getPodName())->toBe('database');
});

test('cache driver select options are valid', function () {
    $options = CacheDriver::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('redis', 'Redis');
});
