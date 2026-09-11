<?php

use App\Data\ConfigData;
use App\Enums\PackageManager;

/**
 * Only gatherConfig() (used by `new`/`init`) prompts for a package manager, so
 * statamic:new and wordpress:new left ConfigData::$packageManager null. The JS
 * install path dereferenced the raw property, so the first component to
 * contribute any JS dependency killed the scaffold with "Call to a member
 * function buildCommand() on null" — after the project was already on disk.
 */
test('a config that never chose a package manager still resolves one', function (): void {
    $config = new ConfigData;

    expect($config->packageManager)->toBeNull()
        ->and($config->getPackageManager())->toBe(PackageManager::NPM)
        ->and($config->getPackageManager()->buildCommand())->toBeString();
});

test('the JS install path never dereferences the nullable property', function (): void {
    $source = (string) file_get_contents(base_path('app/Traits/InteractsWithArchitecturalEngine.php'));

    expect($source)->not->toContain('$config->packageManager->')
        ->and(substr_count($source, 'getPackageManager()->buildCommand()'))->toBe(2);
});

test('statamic:new records its package manager explicitly', function (): void {
    $source = (string) file_get_contents(base_path('app/Commands/Statamic/StatamicNewCommand.php'));

    expect($source)->toContain('$config->setPackageManager(PackageManager::NPM)');
});
