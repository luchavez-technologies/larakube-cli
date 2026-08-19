<?php

use App\Enums\AppFramework;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Traits\SupportedDriversTrait;

it('correctly maps supported database drivers for AppFramework', function (): void {
    expect(AppFramework::LARAVEL->supportedDatabaseDrivers())
        ->toContain(DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB, DatabaseDriver::SQLITE)
        ->and(AppFramework::STATAMIC->supportedDatabaseDrivers())->toContain(DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB, DatabaseDriver::SQLITE)
        ->and(AppFramework::WORDPRESS->supportedDatabaseDrivers())->toContain(DatabaseDriver::MYSQL, DatabaseDriver::MARIADB)->not->toContain(DatabaseDriver::SQLITE);
});

it('correctly maps supported database drivers for ClusterTool', function (): void {
    $traitObject = new class
    {
        use SupportedDriversTrait;
    };

    expect($traitObject->getSupportedDatabaseDrivers(ClusterTool::CHAT))
        ->toEqual([DatabaseDriver::POSTGRESQL])
        ->and($traitObject->getSupportedDatabaseDrivers(ClusterTool::TASKS))->toContain(DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL);
});
