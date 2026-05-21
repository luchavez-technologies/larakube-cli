<?php

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\LaravelFeature;
use App\Enums\ServerVariation;

test('Dependency Resolution: Horizon automatically adds Redis', function () {
    $config = new ConfigData(name: 'test-app');
    $config->addFeature(LaravelFeature::HORIZON);

    $config->resolveDependencies();

    expect($config->getCacheDrivers())->toContain(CacheDriver::REDIS);
});

test('Dependency Resolution: Octane automatically pivots to FrankenPHP', function () {
    $config = new ConfigData(name: 'test-app');
    $config->addFeature(LaravelFeature::OCTANE);

    $config->resolveDependencies();

    expect($config->getServerVariation())->toBe(ServerVariation::FRANKENPHP);
});

test('Dependency Resolution: Reverb adds its own feature requirements', function () {
    $config = new ConfigData(name: 'test-app');
    $config->addFeature(LaravelFeature::REVERB);

    $config->resolveDependencies();

    // Reverb currently doesn't have hard enum dependencies, but we can
    // add tests here if we introduce them (e.g. Reverb requiring Redis for scaling)
    expect($config->hasFeature(LaravelFeature::REVERB))->toBeTrue();
});
