<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\PackageManager;
use App\Enums\ServerVariation;

test('Feature: Kitchen Sink (Queues + Scheduler + Reverb + MCP + Boost)', function (): void {
    $config = new ConfigData(name: 'kitchen-sink');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addFeature(
        LaravelFeature::QUEUES,
        LaravelFeature::TASK_SCHEDULING,
        LaravelFeature::REVERB,
        LaravelFeature::MCP,
        LaravelFeature::BOOST,
    );
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Feature: Horizon (Auto-resolves Redis)', function (): void {
    $config = new ConfigData(name: 'horizon-auto-redis');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addFeature(LaravelFeature::HORIZON);

    $manifests = generateManifests($config);
    expect($manifests)->toContain('--- FILE: base/redis-deployment.yaml ---')
        ->toMatchSnapshot();
});

test('Feature: Octane + Postgres (Auto-resolves FrankenPHP)', function (): void {
    $config = new ConfigData(name: 'octane-auto-franken');
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->addFeature(LaravelFeature::OCTANE);

    $manifests = generateManifests($config);
    expect($manifests)->toMatchSnapshot();
});

test('Feature: SSR (Inertia Server-Side Rendering)', function (): void {
    $config = new ConfigData(name: 'ssr-test');
    $config->setServerVariation(ServerVariation::FRANKENPHP);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setFrontend(FrontendStack::REACT);
    $config->setPackageManager(PackageManager::NPM);
    $config->addFeature(LaravelFeature::SSR);

    $manifests = generateManifests($config);

    // SSR pod is production-only — must land in overlays/production, NEVER in base.
    expect($manifests)->toContain('--- FILE: overlays/production/ssr-deployment.yaml ---')
        ->and($manifests)->not->toContain('--- FILE: base/ssr-deployment.yaml ---')
        ->and($manifests)->toContain('node-ssr')
        ->and($manifests)->toContain('containerPort: 13714')
        ->and($manifests)->toContain('imagePullPolicy: Always')
        ->and($manifests)->toMatchSnapshot();
});
