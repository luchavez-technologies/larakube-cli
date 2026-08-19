<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;

test('Services: Meilisearch', function (): void {
    $config = new ConfigData(name: 'meilisearch-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addScoutDriver(SearchDriver::MEILISEARCH);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Services: Typesense', function (): void {
    $config = new ConfigData(name: 'typesense-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addScoutDriver(SearchDriver::TYPESENSE);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Services: MinIO', function (): void {
    $config = new ConfigData(name: 'minio-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addObjectStorage(StorageDriver::MINIO);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Services: SeaweedFS', function (): void {
    $config = new ConfigData(name: 'seaweed-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addObjectStorage(StorageDriver::SEAWEEDFS);
    expect(generateManifests($config))->toMatchSnapshot();
});

test('Services: Garage', function (): void {
    $config = new ConfigData(name: 'garage-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->addObjectStorage(StorageDriver::GARAGE);
    expect(generateManifests($config))->toMatchSnapshot();
});
