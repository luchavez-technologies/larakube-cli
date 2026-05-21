<?php

use App\Enums\PackageManager;

test('package manager has correct labels', function () {
    expect(PackageManager::NPM->getLabel())->toBe('NPM')
        ->and(PackageManager::PNPM->getLabel())->toBe('PNPM')
        ->and(PackageManager::BUN->getLabel())->toBe('Bun')
        ->and(PackageManager::YARN->getLabel())->toBe('Yarn');
});

test('package manager install commands', function () {
    expect(PackageManager::NPM->installCommand())->toBe('npm install')
        ->and(PackageManager::PNPM->installCommand())->toBe('pnpm install')
        ->and(PackageManager::BUN->installCommand())->toBe('bun install')
        ->and(PackageManager::YARN->installCommand())->toBe('yarn install');
});

test('package manager build commands', function () {
    expect(PackageManager::NPM->buildCommand())->toBe('npm run build')
        ->and(PackageManager::YARN->buildCommand())->toBe('yarn build');
});

test('package manager dev commands', function () {
    expect(PackageManager::NPM->devCommand())->toBe('npm run dev --')
        ->and(PackageManager::PNPM->devCommand())->toBe('pnpm dev');
});

test('package manager select options are valid', function () {
    $options = PackageManager::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('npm', 'NPM');
});
