<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\ServerVariation;

test('Feature: Kitchen Sink (Queues + Scheduler + Reverb + MCP + Boost)', function () {
    $config = new ConfigData(name: 'kitchen-sink');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addFeature(
        LaravelFeature::QUEUES,
        LaravelFeature::TASK_SCHEDULING,
        LaravelFeature::REVERB,
        LaravelFeature::MCP,
        LaravelFeature::BOOST
    );
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Feature: Horizon (Auto-resolves Redis)', function () {
    $config = new ConfigData(name: 'horizon-auto-redis');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addFeature(LaravelFeature::HORIZON);

    $manifests = generateManifests($config);
    expect($manifests)->toContain('--- FILE: base/redis-deployment.yaml ---');
    expect($manifests)->toMatchSnapshot();
});

test('Feature: Octane + Postgres (Auto-resolves FrankenPHP)', function () {
    $config = new ConfigData(name: 'octane-auto-franken');
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->addFeature(LaravelFeature::OCTANE);

    $manifests = generateManifests($config);
    expect($manifests)->toMatchSnapshot();
});
