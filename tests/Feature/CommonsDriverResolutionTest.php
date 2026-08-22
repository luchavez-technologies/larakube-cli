<?php

namespace Tests\Feature;

use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;
use App\Traits\SupportedDriversTrait;
use Illuminate\Support\Facades\Process;

class CommonsDriverResolutionTest
{
    use SupportedDriversTrait;
}

test('SupportedDriversTrait resolves database driver for single supported tool', function (): void {
    $tester = new CommonsDriverResolutionTest;
    $driver = $tester->resolveToolDatabaseDriver('kubectl', ClusterTool::CHAT);
    expect($driver)->toBe(DatabaseDriver::POSTGRESQL);
});

test('SupportedDriversTrait respects explicit database driver option', function (): void {
    $tester = new CommonsDriverResolutionTest;
    $driver = $tester->resolveToolDatabaseDriver('kubectl', ClusterTool::GIT, 'mysql');
    expect($driver)->toBe(DatabaseDriver::MYSQL);
});

test('SupportedDriversTrait resolves active database driver from cluster probe', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => Process::result(output: json_encode([
            'services' => [
                'mysql' => ['enabled' => true],
            ],
        ])),
        '*' => Process::result(),
    ]);

    $tester = new CommonsDriverResolutionTest;
    $driver = $tester->resolveToolDatabaseDriver('kubectl', ClusterTool::GIT);
    expect($driver)->toBe(DatabaseDriver::MYSQL);
});

test('SupportedDriversTrait resolves cache driver for tool', function (): void {
    Process::fake([
        '*get deployment redis*' => Process::result(output: 'redis'),
        '*' => Process::result(),
    ]);

    $tester = new CommonsDriverResolutionTest;
    $driver = $tester->resolveToolCacheDriver('kubectl', ClusterTool::GIT);
    expect($driver)->toBe(CacheDriver::REDIS);
});

test('SupportedDriversTrait resolves storage driver for tool', function (): void {
    Process::fake([
        '*get deployment minio*' => Process::result(output: 'minio'),
        '*' => Process::result(),
    ]);

    $tester = new CommonsDriverResolutionTest;
    $driver = $tester->resolveToolStorageDriver('kubectl', ClusterTool::SIGN);
    expect($driver)->toBe(StorageDriver::MINIO);
});

test('SupportedDriversTrait resolves search driver for app framework', function (): void {
    Process::fake([
        '*get deployment meilisearch*' => Process::result(output: 'meilisearch'),
        '*' => Process::result(),
    ]);

    $tester = new CommonsDriverResolutionTest;
    $driver = $tester->resolveToolSearchDriver('kubectl', AppFramework::LARAVEL);
    expect($driver)->toBe(SearchDriver::MEILISEARCH);
});
