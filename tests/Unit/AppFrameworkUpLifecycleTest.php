<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\DatabaseDriver;
use App\Enums\FrontendStack;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('requiresEnvFile returns false for static SPAs and true for server frameworks', function (): void {
    expect(AppFramework::VITE->requiresEnvFile())->toBeFalse()
        ->and(AppFramework::ASTRO->requiresEnvFile())->toBeFalse()
        ->and(AppFramework::DOCUSAURUS->requiresEnvFile())->toBeFalse()
        ->and(AppFramework::LARAVEL->requiresEnvFile())->toBeTrue()
        ->and(AppFramework::STATAMIC->requiresEnvFile())->toBeTrue()
        ->and(AppFramework::WORDPRESS->requiresEnvFile())->toBeTrue()
        ->and(AppFramework::NEXTJS->requiresEnvFile())->toBeTrue()
        ->and(AppFramework::DJANGO->requiresEnvFile())->toBeTrue()
        ->and(AppFramework::FASTAPI->requiresEnvFile())->toBeTrue()
        ->and(AppFramework::SPRINGBOOT->requiresEnvFile())->toBeTrue()
        ->and(AppFramework::DOTNET->requiresEnvFile())->toBeTrue()
        ->and(AppFramework::GIN->requiresEnvFile())->toBeTrue()
        ->and(AppFramework::AXUM->requiresEnvFile())->toBeTrue()
        ->and(AppFramework::NESTJS->requiresEnvFile())->toBeTrue()
        ->and(AppFramework::ADONISJS->requiresEnvFile())->toBeTrue();
});

test('postEnvSetupCommand returns key:generate for Laravel and Statamic and null for others', function (): void {
    expect(AppFramework::LARAVEL->postEnvSetupCommand())->toBe('php artisan key:generate --no-interaction')
        ->and(AppFramework::STATAMIC->postEnvSetupCommand())->toBe('php artisan key:generate --no-interaction')
        ->and(AppFramework::WORDPRESS->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::NEXTJS->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::DJANGO->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::FASTAPI->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::SPRINGBOOT->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::DOTNET->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::GIN->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::AXUM->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::NESTJS->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::ADONISJS->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::VITE->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::ASTRO->postEnvSetupCommand())->toBeNull()
        ->and(AppFramework::DOCUSAURUS->postEnvSetupCommand())->toBeNull();
});

test('hasMissingDependencies detects missing vendor and node_modules appropriately', function (): void {
    $dir = (new TemporaryDirectory)->create();
    $path = $dir->path();

    $config = new ConfigData(id: 'test', name: 'test', path: $path, framework: AppFramework::LARAVEL);

    // Empty dir: PHP project missing vendor
    expect(AppFramework::LARAVEL->hasMissingDependencies($path, $config))->toBeTrue();

    // Add vendor/
    mkdir("{$path}/vendor");
    // Without frontend/SSR: vendor is enough
    expect(AppFramework::LARAVEL->hasMissingDependencies($path, $config))->toBeFalse();

    // Now configure frontend
    $configWithFrontend = new ConfigData(
        id: 'test',
        name: 'test',
        path: $path,
        framework: AppFramework::LARAVEL,
        frontend: FrontendStack::REACT,
    );
    expect(AppFramework::LARAVEL->hasMissingDependencies($path, $configWithFrontend))->toBeTrue();

    // Add node_modules/
    mkdir("{$path}/node_modules");
    expect(AppFramework::LARAVEL->hasMissingDependencies($path, $configWithFrontend))->toBeFalse();

    // Node frameworks: require node_modules
    $nodeDir = (new TemporaryDirectory)->create();
    $nodePath = $nodeDir->path();
    $nodeConfig = new ConfigData(id: 'node-app', name: 'node-app', path: $nodePath, framework: AppFramework::NEXTJS);

    expect(AppFramework::NEXTJS->hasMissingDependencies($nodePath, $nodeConfig))->toBeTrue();
    mkdir("{$nodePath}/node_modules");
    expect(AppFramework::NEXTJS->hasMissingDependencies($nodePath, $nodeConfig))->toBeFalse();

    // Static SPAs: dev pod init container handles node_modules, host check returns false
    expect(AppFramework::VITE->hasMissingDependencies($nodePath, $nodeConfig))->toBeFalse()
        ->and(AppFramework::ASTRO->hasMissingDependencies($nodePath, $nodeConfig))->toBeFalse()
        ->and(AppFramework::DOCUSAURUS->hasMissingDependencies($nodePath, $nodeConfig))->toBeFalse();

    // Compiled / server apps: return false
    expect(AppFramework::GIN->hasMissingDependencies($nodePath, $nodeConfig))->toBeFalse()
        ->and(AppFramework::AXUM->hasMissingDependencies($nodePath, $nodeConfig))->toBeFalse()
        ->and(AppFramework::DJANGO->hasMissingDependencies($nodePath, $nodeConfig))->toBeFalse();

    $dir->delete();
    $nodeDir->delete();
});

test('usesLocalSqlite returns true only when SQLite is configured and supported', function (): void {
    $configWithSqlite = new ConfigData(
        id: 'test',
        name: 'test',
        path: '/tmp/test',
        framework: AppFramework::LARAVEL,
        database: DatabaseDriver::SQLITE,
    );

    $configWithPgsql = new ConfigData(
        id: 'test',
        name: 'test',
        path: '/tmp/test',
        framework: AppFramework::LARAVEL,
        database: DatabaseDriver::POSTGRESQL,
    );

    $wordpressWithSqlite = new ConfigData(
        id: 'test',
        name: 'test',
        path: '/tmp/test',
        framework: AppFramework::WORDPRESS,
        database: DatabaseDriver::SQLITE,
    );

    expect(AppFramework::LARAVEL->usesLocalSqlite($configWithSqlite))->toBeTrue()
        ->and(AppFramework::LARAVEL->usesLocalSqlite($configWithPgsql))->toBeFalse()
        // WordPress Bedrock only supports MySQL/MariaDB
        ->and(AppFramework::WORDPRESS->usesLocalSqlite($wordpressWithSqlite))->toBeFalse()
        ->and(AppFramework::VITE->usesLocalSqlite($configWithSqlite))->toBeFalse();
});

test('localPersistentVolumeNames returns storage and data PVs for PHP and empty array for non-PHP', function (): void {
    $configWithSqlite = new ConfigData(
        id: 'my-app',
        name: 'my-app',
        path: '/tmp/my-app',
        framework: AppFramework::LARAVEL,
        database: DatabaseDriver::SQLITE,
    );

    $configWithPgsql = new ConfigData(
        id: 'my-app',
        name: 'my-app',
        path: '/tmp/my-app',
        framework: AppFramework::LARAVEL,
        database: DatabaseDriver::POSTGRESQL,
    );

    $nextConfig = new ConfigData(
        id: 'my-app',
        name: 'my-app',
        path: '/tmp/my-app',
        framework: AppFramework::NEXTJS,
        database: DatabaseDriver::POSTGRESQL,
    );

    expect(AppFramework::LARAVEL->localPersistentVolumeNames('my-app', $configWithSqlite))->toBe([
        'my-app-laravel-storage-pv',
        'my-app-laravel-data-pv',
    ])
        ->and(AppFramework::LARAVEL->localPersistentVolumeNames('my-app', $configWithPgsql))->toBe([
            'my-app-laravel-storage-pv',
        ])
        ->and(AppFramework::NEXTJS->localPersistentVolumeNames('my-app', $nextConfig))->toBeEmpty()
        ->and(AppFramework::GIN->localPersistentVolumeNames('my-app', $nextConfig))->toBeEmpty()
        ->and(AppFramework::VITE->localPersistentVolumeNames('my-app', $nextConfig))->toBeEmpty();
});
