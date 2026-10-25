<?php

use App\Data\ConfigData;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function inTempPlexProject(array $envConfig, callable $fn): void
{
    $tempDir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $tempDir->path();
    file_put_contents($dir.'/.larakube.json', json_encode([
        'id' => 'shop-id',
        'name' => 'shop',
        'framework' => 'laravel',
        'database' => 'postgres',
        'cacheDriver' => 'redis',
        'environments' => [
            'local' => array_merge([
                'managed' => ['postgres', 'redis'],
                'plex' => ['postgres', 'redis'],
                'hosts' => ['web' => 'shop.dev.test'],
            ], $envConfig),
        ],
    ], JSON_PRETTY_PRINT));

    $original = getcwd();
    chdir($dir);

    try {
        $fn($dir);
    } finally {
        chdir($original);
        $tempDir->delete();
    }
}

test('plex:leave with --service=postgres leaves postgres and retains redis in commons', function (): void {
    Process::fake([
        '*cluster-info*' => Process::result(output: 'Kubernetes control plane is running'),
        '*get configmap plex-registry*' => Process::result(
            output: json_encode(['tenants' => [
                'shop_local' => [
                    'db' => 'shop_local',
                    'db_service' => 'postgres',
                    'redis_index' => 3,
                    'namespace' => 'shop-local',
                ],
            ]]),
        ),
        '*' => Process::result(output: ''),
    ]);

    inTempPlexProject([], function (string $dir): void {
        $code = Artisan::call('plex:leave', [
            'environment' => 'local',
            '--service' => ['postgres'],
            '--force' => true,
            '--no-interaction' => true,
        ]);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getPlex('local'))->toBe(['redis'])
            ->and($saved->getManaged('local'))->toBe(['redis']);
    });
});

test('plex:leave with --service=redis leaves redis and retains postgres in commons', function (): void {
    Process::fake([
        '*cluster-info*' => Process::result(output: 'Kubernetes control plane is running'),
        '*get configmap plex-registry*' => Process::result(
            output: json_encode(['tenants' => [
                'shop_local' => [
                    'db' => 'shop_local',
                    'db_service' => 'postgres',
                    'redis_index' => 3,
                    'namespace' => 'shop-local',
                ],
            ]]),
        ),
        '*' => Process::result(output: ''),
    ]);

    inTempPlexProject([], function (string $dir): void {
        $code = Artisan::call('plex:leave', [
            'environment' => 'local',
            '--service' => ['redis'],
            '--force' => true,
            '--no-interaction' => true,
        ]);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getPlex('local'))->toBe(['postgres'])
            ->and($saved->getManaged('local'))->toBe(['postgres']);
    });
});

test('plex:leave with no service option leaves all commons services', function (): void {
    Process::fake([
        '*cluster-info*' => Process::result(output: 'Kubernetes control plane is running'),
        '*get configmap plex-registry*' => Process::result(
            output: json_encode(['tenants' => [
                'shop_local' => [
                    'db' => 'shop_local',
                    'db_service' => 'postgres',
                    'redis_index' => 3,
                    'namespace' => 'shop-local',
                ],
            ]]),
        ),
        '*' => Process::result(output: ''),
    ]);

    inTempPlexProject([], function (string $dir): void {
        $code = Artisan::call('plex:leave', [
            'environment' => 'local',
            '--force' => true,
            '--no-interaction' => true,
        ]);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getPlex('local'))->toBeEmpty()
            ->and($saved->getManaged('local'))->toBeEmpty();
    });
});

test('plex:leave --restore with --service=postgres only drops database from registry and preserves redis', function (): void {
    $savedRegistry = null;

    Process::fake([
        '*cluster-info*' => Process::result(output: 'Kubernetes control plane is running'),
        '*get configmap plex-registry*' => Process::result(
            output: json_encode(['tenants' => [
                'shop_local' => [
                    'db' => 'shop_local',
                    'db_service' => 'postgres',
                    'redis_index' => 3,
                    'namespace' => 'shop-local',
                ],
            ]]),
        ),
        '*exec -n * > *' => function ($p) {
            if (preg_match('/> \'?(.*?\.sql)\'?/', $p->command, $m)) {
                file_put_contents(trim($m[1], "'\""), 'CREATE TABLE test;');
            }

            return Process::result(output: '', exitCode: 0);
        },
        '*get deploy *' => Process::result(output: "1\n"),
        '*jsonpath={.status.readyReplicas}*' => Process::result(output: "1\n"),
        '*deploy/postgres*' => Process::result(output: "1\n"),
        '*create configmap plex-registry*' => function ($p) use (&$savedRegistry) {
            $savedRegistry = $p->command;

            return Process::result(output: 'configured');
        },
        '*' => Process::result(output: ''),
    ]);

    inTempPlexProject(['plex' => ['redis'], 'managed' => ['redis']], function (): void {
        $code = Artisan::call('plex:leave', [
            'environment' => 'local',
            '--service' => ['postgres'],
            '--restore' => true,
            '--no-backup' => true,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        expect($code)->toBe(0);
    });
});
