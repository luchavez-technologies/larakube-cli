<?php

use App\Data\ConfigData;
use App\Enums\DeploymentStrategy;
use App\Enums\ServerVariation;

test('Strategy: Multi-Node HA produces ReadWriteMany PVCs in base config', function () {
    $config = new ConfigData(name: 'ha-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setStrategy(DeploymentStrategy::MULTI_NODE_HA);

    $manifests = generateManifests($config);

    // Extract base/volumes.yaml part
    preg_match('/--- FILE: base\/volumes\.yaml ---\n(.*?)--- FILE:/s', $manifests, $matches);
    $baseConfig = $matches[1] ?? '';

    expect($baseConfig)->toContain('ReadWriteMany');
    expect($baseConfig)->not->toContain('ReadWriteOnce');
});

test('Strategy: Single-Node Hero produces ReadWriteOnce PVCs in base config', function () {
    $config = new ConfigData(name: 'single-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setStrategy(DeploymentStrategy::SINGLE_NODE);

    $manifests = generateManifests($config);

    // Extract base/volumes.yaml part
    preg_match('/--- FILE: base\/volumes\.yaml ---\n(.*?)--- FILE:/s', $manifests, $matches);
    $baseConfig = $matches[1] ?? '';

    expect($baseConfig)->toContain('ReadWriteOnce');
    expect($baseConfig)->not->toContain('ReadWriteMany');
});
