<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\FrontendStack;
use App\Enums\PackageManager;
use App\Enums\ServerVariation;

test('Frontend: Nginx + SQLite + React', function (): void {
    $config = new ConfigData(name: 'react-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setFrontend(FrontendStack::REACT);
    $config->setPackageManager(PackageManager::NPM);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Frontend: Nginx + SQLite + Vue', function (): void {
    $config = new ConfigData(name: 'vue-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setFrontend(FrontendStack::VUE);
    $config->setPackageManager(PackageManager::NPM);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Frontend: Nginx + SQLite + Svelte', function (): void {
    $config = new ConfigData(name: 'svelte-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setFrontend(FrontendStack::SVELTE);
    $config->setPackageManager(PackageManager::NPM);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Frontend: Nginx + SQLite + Livewire', function (): void {
    $config = new ConfigData(name: 'livewire-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setFrontend(FrontendStack::LIVEWIRE);
    $config->setPackageManager(PackageManager::NPM);
    expect(generateManifests($config))->toMatchSnapshot();
});
