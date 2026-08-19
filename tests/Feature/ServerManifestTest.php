<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\ServerVariation;

test('Server: Nginx + SQLite', function (): void {
    $config = new ConfigData(name: 'nginx-sqlite');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Server: Apache + SQLite', function (): void {
    $config = new ConfigData(name: 'apache-sqlite');
    $config->setServerVariation(ServerVariation::FPM_APACHE);
    $config->setDatabase(DatabaseDriver::SQLITE);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Server: FrankenPHP + Postgres', function (): void {
    $config = new ConfigData(name: 'franken-postgres');
    $config->setServerVariation(ServerVariation::FRANKENPHP);
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Server: FrankenPHP + SQLite (Should be error)', function (): void {
    $config = new ConfigData(name: 'franken-sqlite');
    $config->setServerVariation(ServerVariation::FRANKENPHP);
    $config->setDatabase(DatabaseDriver::SQLITE);

    // We snapshot the behavior, which currently allows it but skips volume generation
    expect(generateManifests($config))->toMatchSnapshot();
});
