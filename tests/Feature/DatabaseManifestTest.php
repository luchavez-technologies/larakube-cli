<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\ServerVariation;

test('Database: Nginx + MySQL', function (): void {
    $config = new ConfigData(name: 'mysql-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::MYSQL);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Database: Nginx + MariaDB', function (): void {
    $config = new ConfigData(name: 'mariadb-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::MARIADB);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Database: Nginx + MongoDB', function (): void {
    $config = new ConfigData(name: 'mongodb-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::MONGODB);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Database: Nginx + Postgres', function (): void {
    $config = new ConfigData(name: 'postgres-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    expect(generateManifests($config))->toMatchSnapshot();
});
