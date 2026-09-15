<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithArchitecturalEngine;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** Records what the install pass would run in a container instead of running it. */
function engineInstallHost(): object
{
    return new class
    {
        use InteractsWithArchitecturalEngine;

        /** @var list<string> */
        public array $ran = [];

        protected function runInContainer(string $command, string $path, string $type = 'php', string $envs = ''): void
        {
            $this->ran[] = $command;
        }

        protected function laraKubeInfo(string $message): void {}
    };
}

function engineInstallConfig(TemporaryDirectory $dir, ?AppFramework $framework): ConfigData
{
    $config = new ConfigData;
    $config->setIsScaffolding(true);
    $config->setName('engine-app');
    $config->setPath($dir->path());
    $config->framework = $framework;
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->setObjectStorage(StorageDriver::MINIO);

    return $config;
}

test('a non-PHP app gets no composer, artisan or Laravel JS step', function (AppFramework $framework): void {
    $dir = TemporaryDirectory::make();
    $host = engineInstallHost();

    $host->installComponents(engineInstallConfig($dir, $framework));
    $host->installComponent(engineInstallConfig($dir, $framework), StorageDriver::MINIO);

    expect($host->ran)->toBe([]);

    $dir->delete();
})->with([AppFramework::NEXTJS, AppFramework::DJANGO, AppFramework::GIN]);

test('a non-PHP app still gets the components\' env values', function (): void {
    $dir = TemporaryDirectory::make();
    file_put_contents($dir->path().'/.env', '');

    engineInstallHost()->installComponents(engineInstallConfig($dir, AppFramework::NEXTJS));

    expect(trim((string) file_get_contents($dir->path().'/.env')))->not->toBeEmpty();

    $dir->delete();
});

test('a Laravel app still installs its components\' composer packages', function (?AppFramework $framework): void {
    $dir = TemporaryDirectory::make();
    $host = engineInstallHost();

    $host->installComponents(engineInstallConfig($dir, $framework));

    expect(implode("\n", $host->ran))->toContain('composer require')
        ->toContain('league/flysystem-aws-s3-v3');

    $dir->delete();
})->with([
    'no framework recorded' => [null],
    'laravel' => [AppFramework::LARAVEL],
]);
