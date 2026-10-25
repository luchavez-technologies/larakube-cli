<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function inTempLaraKubeProject(array $configData, callable $fn): void
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

test('framework detection correctly distinguishes php and laravel runtimes', function (): void {
    $laravel = new ConfigData(framework: AppFramework::LARAVEL);
    expect($laravel->isPhp())->toBeTrue()
        ->and($laravel->isLaravel())->toBeTrue();

    $statamic = new ConfigData(framework: AppFramework::STATAMIC);
    expect($statamic->isPhp())->toBeTrue()
        ->and($statamic->isLaravel())->toBeTrue();

    $wordpress = new ConfigData(framework: AppFramework::WORDPRESS);
    expect($wordpress->isPhp())->toBeTrue()
        ->and($wordpress->isLaravel())->toBeFalse();

    $nextjs = new ConfigData(framework: AppFramework::NEXTJS);
    expect($nextjs->isPhp())->toBeFalse()
        ->and($nextjs->isLaravel())->toBeFalse();

    $fastapi = new ConfigData(framework: AppFramework::FASTAPI);
    expect($fastapi->isPhp())->toBeFalse()
        ->and($fastapi->isLaravel())->toBeFalse();

    $default = new ConfigData; // null framework defaults to Laravel
    expect($default->isPhp())->toBeTrue()
        ->and($default->isLaravel())->toBeTrue();
});

test('feature:add command signature adds components to project configuration', function (): void {
    inTempLaraKubeProject(['databases' => [], 'database' => null], function (string $dir): void {
        $code = Artisan::call('feature:add', [
            'items' => ['postgres'],
            '--no-interaction' => true,
        ]);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getDatabases())->toContain(DatabaseDriver::POSTGRESQL);
    });
});

test('feature:add supports backwards-compatible add alias', function (): void {
    inTempLaraKubeProject(['cacheDrivers' => [], 'cacheDriver' => null], function (string $dir): void {
        $code = Artisan::call('add', [
            'items' => ['redis'],
            '--no-interaction' => true,
        ]);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getCacheDrivers())->toContain(CacheDriver::REDIS);
    });
});

test('feature:add supports updating php version via positional argument and flag', function (): void {
    inTempLaraKubeProject(['phpVersion' => '8.5'], function (string $dir): void {
        $code = Artisan::call('feature:add', [
            'items' => ['8.4'],
            '--no-interaction' => true,
        ]);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getPhpVersion()->value)->toBe('8.4');
    });

    inTempLaraKubeProject(['phpVersion' => '8.5'], function (string $dir): void {
        $code = Artisan::call('feature:add', [
            '--8.3' => true,
            '--no-interaction' => true,
        ]);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getPhpVersion()->value)->toBe('8.3');
    });
});
