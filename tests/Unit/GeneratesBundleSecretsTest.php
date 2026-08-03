<?php

namespace Tests\Unit;

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;

test('it always generates an app key', function () {
    $generator = new SecretsGenerator;
    $config = new ConfigData(name: 'test');

    $secrets = $generator->generateInstallSecrets($config, 'production');

    expect($secrets)->toHaveKey('APP_KEY')
        ->and($secrets['APP_KEY'])->toStartWith('base64:');
});

test('it generates database passwords for external databases', function () {
    $generator = new SecretsGenerator;
    $config = new ConfigData(name: 'test', database: DatabaseDriver::POSTGRESQL);

    $secrets = $generator->generateInstallSecrets($config, 'production');

    expect($secrets)->toHaveKey('DB_PASSWORD')
        ->and(strlen($secrets['DB_PASSWORD']))->toBe(32);
});

test('it does not generate database passwords for sqlite', function () {
    $generator = new SecretsGenerator;
    $config = new ConfigData(name: 'test', database: DatabaseDriver::SQLITE);

    $secrets = $generator->generateInstallSecrets($config, 'production');

    expect($secrets)->not->toHaveKey('DB_PASSWORD');
});

test('it generates mongodb uri with credentials', function () {
    $generator = new SecretsGenerator;
    $config = new ConfigData(name: 'test', database: DatabaseDriver::MONGODB);

    $secrets = $generator->generateInstallSecrets($config, 'production');

    expect($secrets)->toHaveKey('DB_PASSWORD')
        ->and($secrets)->toHaveKey('DB_URI')
        ->and($secrets['DB_URI'])->toContain('mongodb://root:')
        ->and($secrets['DB_URI'])->toContain('@mongodb.test-production.svc.cluster.local:27017');
});

test('it generates reverb keys when enabled', function () {
    $generator = new SecretsGenerator;
    $config = new ConfigData(name: 'test', features: [LaravelFeature::REVERB]);

    $secrets = $generator->generateInstallSecrets($config, 'production');

    expect($secrets)->toHaveKey('REVERB_APP_ID')
        ->and($secrets)->toHaveKey('REVERB_APP_KEY')
        ->and($secrets)->toHaveKey('REVERB_APP_SECRET');
});

test('it generates storage secrets when enabled', function () {
    $generator = new SecretsGenerator;
    $config = new ConfigData(name: 'test', objectStorage: StorageDriver::MINIO);

    $secrets = $generator->generateInstallSecrets($config, 'production');

    expect($secrets)->toHaveKey('AWS_SECRET_ACCESS_KEY');
});

test('it generates scout secrets when enabled', function () {
    $generator = new SecretsGenerator;
    $configMeili = new ConfigData(name: 'test', scoutDriver: SearchDriver::MEILISEARCH);
    $secretsMeili = $generator->generateInstallSecrets($configMeili, 'production');
    expect($secretsMeili)->toHaveKey('MEILISEARCH_KEY');

    $configType = new ConfigData(name: 'test', scoutDriver: SearchDriver::TYPESENSE);
    $secretsType = $generator->generateInstallSecrets($configType, 'production');
    expect($secretsType)->toHaveKey('TYPESENSE_API_KEY');
});

test('it preserves existing secrets when provided', function () {
    $generator = new SecretsGenerator;
    $config = new ConfigData(name: 'test', database: DatabaseDriver::POSTGRESQL);

    $existing = [
        'APP_KEY' => 'base64:existing_key',
        'DB_PASSWORD' => 'existing_password',
    ];

    $secrets = $generator->generateInstallSecrets($config, 'production', $existing);

    expect($secrets['APP_KEY'])->toBe('base64:existing_key')
        ->and($secrets['DB_PASSWORD'])->toBe('existing_password');
});
