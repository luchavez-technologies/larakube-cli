<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;

test('database driver has correct labels', function () {
    expect(DatabaseDriver::MYSQL->getLabel())->toBe('MySQL')
        ->and(DatabaseDriver::MARIADB->getLabel())->toBe('MariaDB')
        ->and(DatabaseDriver::POSTGRESQL->getLabel())->toBe('PostgreSQL')
        ->and(DatabaseDriver::MONGODB->getLabel())->toBe('MongoDB')
        ->and(DatabaseDriver::SQLITE->getLabel())->toBe('SQLite (Local File)');
});

test('database driver has correct ports', function () {
    expect(DatabaseDriver::MYSQL->dbPort())->toBe(3306)
        ->and(DatabaseDriver::MARIADB->dbPort())->toBe(3306)
        ->and(DatabaseDriver::POSTGRESQL->dbPort())->toBe(5432)
        ->and(DatabaseDriver::MONGODB->dbPort())->toBe(27017)
        ->and(DatabaseDriver::SQLITE->dbPort())->toBe(0);
});

test('database driver has correct connections', function () {
    expect(DatabaseDriver::MYSQL->dbConnection())->toBe('mysql')
        ->and(DatabaseDriver::POSTGRESQL->dbConnection())->toBe('pgsql')
        ->and(DatabaseDriver::MONGODB->dbConnection())->toBe('mongodb')
        ->and(DatabaseDriver::SQLITE->dbConnection())->toBe('sqlite');
});

test('sqlite is hidden when using frankenphp', function () {
    $config = ConfigData::from(['serverVariation' => 'frankenphp']);
    expect(DatabaseDriver::SQLITE->isHidden($config))->toBeTrue();

    $config = ConfigData::from(['serverVariation' => 'fpm-nginx']);
    expect(DatabaseDriver::SQLITE->isHidden($config))->toBeFalse();
});

test('database driver select options are valid', function () {
    $options = DatabaseDriver::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('mysql', 'MySQL');
});
