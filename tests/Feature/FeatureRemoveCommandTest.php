<?php

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function inTempLaraKubeRemoveProject(array $configData, callable $fn): void
{
    Process::fake([
        'command -v *' => Process::result(),
        'which *' => Process::result(),
        '* info' => Process::result(),
        '*' => Process::result(),
    ]);

    $tempDir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $tempDir->path();
    file_put_contents($dir.'/.larakube.json', json_encode(array_merge([
        'id' => 'demo-project-id',
        'name' => 'demo',
        'framework' => 'laravel',
        'environments' => [
            'local' => [
                'managed' => [],
                'hosts' => ['web' => 'demo.dev.test'],
            ],
        ],
    ], $configData), JSON_PRETTY_PRINT));

    $original = getcwd();
    chdir($dir);

    try {
        $fn($dir);
    } finally {
        chdir($original);
        $tempDir->delete();
    }
}

test('feature:remove dry-run preview operates without modifying config', function (): void {
    inTempLaraKubeRemoveProject([
        'databases' => ['postgres'],
        'database' => 'postgres',
    ], function (string $dir): void {
        $code = Artisan::call('feature:remove', [
            'items' => ['postgres'],
            '--dry-run' => true,
        ]);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getDatabases())->toContain(DatabaseDriver::POSTGRESQL);
    });
});

test('feature:remove supports backwards-compatible remove alias', function (): void {
    inTempLaraKubeRemoveProject([
        'cacheDrivers' => ['redis'],
        'cacheDriver' => 'redis',
    ], function (string $dir): void {
        $code = Artisan::call('remove', [
            'items' => ['redis'],
            '--dry-run' => true,
        ]);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getCacheDrivers())->toContain(CacheDriver::REDIS);
    });
});
