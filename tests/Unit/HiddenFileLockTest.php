<?php

use App\Data\ConfigData;

test('it correctly identifies hidden files as locked', function () {
    $tempDir = sys_get_temp_dir().'/larakube-lock-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    $config = new ConfigData(name: 'lock-test');
    $config->setPath($tempDir);
    $config->addLockedFile('.env');
    $config->addLockedFile('Dockerfile.php');

    // Create the files so realpath works
    touch($tempDir.'/.env');
    touch($tempDir.'/Dockerfile.php');

    // Test with relative paths
    expect($config->isLocked('.env'))->toBeTrue();
    expect($config->isLocked('./.env'))->toBeTrue();
    expect($config->isLocked('Dockerfile.php'))->toBeTrue();
    expect($config->isLocked('./Dockerfile.php'))->toBeTrue();

    // Test with absolute paths
    expect($config->isLocked($tempDir.'/.env'))->toBeTrue();
    expect($config->isLocked($tempDir.'/Dockerfile.php'))->toBeTrue();

    // Test unlocked files
    expect($config->isLocked('config/app.php'))->toBeFalse();

    exec('rm -rf '.escapeshellarg($tempDir));
});
