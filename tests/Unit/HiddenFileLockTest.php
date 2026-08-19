<?php

use App\Data\ConfigData;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('it correctly identifies hidden files as locked', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $config = new ConfigData(name: 'lock-test');
    $config->setPath($tempDir);
    $config->addLockedFile('.env');
    $config->addLockedFile('Dockerfile.php');

    // Create the files so realpath works
    touch($tempDir.'/.env');
    touch($tempDir.'/Dockerfile.php');

    // Test with relative paths
    expect($config->isLocked('.env'))->toBeTrue();
    expect($config->isLocked('./.env'))->toBeTrue()
        ->and($config->isLocked('Dockerfile.php'))->toBeTrue()
        ->and($config->isLocked('./Dockerfile.php'))->toBeTrue();

    // Test with absolute paths
    expect($config->isLocked($tempDir.'/.env'))->toBeTrue();
    expect($config->isLocked($tempDir.'/Dockerfile.php'))->toBeTrue();

    // Test unlocked files
    expect($config->isLocked('config/app.php'))->toBeFalse();

    $temporaryDirectory->delete();
});
