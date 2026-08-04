<?php

use App\Enums\AppFramework;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;

it('correctly maps supported database drivers for AppFramework', function () {
    expect(AppFramework::LARAVEL->supportedDatabaseDrivers())
        ->toContain(DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB, DatabaseDriver::SQLITE);

    expect(AppFramework::STATAMIC->supportedDatabaseDrivers())
        ->toContain(DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB, DatabaseDriver::SQLITE);

    expect(AppFramework::WORDPRESS->supportedDatabaseDrivers())
        ->toContain(DatabaseDriver::MYSQL, DatabaseDriver::MARIADB)
        ->not->toContain(DatabaseDriver::SQLITE);
});

it('correctly maps supported database drivers for ClusterTool', function () {
    $traitObject = new class
    {
        use App\Traits\SupportedDriversTrait;
    };

    expect($traitObject->getSupportedDatabaseDrivers(ClusterTool::CHAT))
        ->toEqual([DatabaseDriver::POSTGRESQL]);

    expect($traitObject->getSupportedDatabaseDrivers(ClusterTool::TASKS))
        ->toContain(DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL);
});
