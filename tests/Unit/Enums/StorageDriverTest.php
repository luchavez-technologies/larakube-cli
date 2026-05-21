<?php

use App\Enums\StorageDriver;

test('storage driver has correct labels', function () {
    expect(StorageDriver::MINIO->getLabel())->toBe('MinIO (Classic)')
        ->and(StorageDriver::SEAWEEDFS->getLabel())->toBe('SeaweedFS (High Performance)')
        ->and(StorageDriver::GARAGE->getLabel())->toBe('Garage (Modern/Rust)');
});

test('storage driver ports', function () {
    expect(StorageDriver::MINIO->port())->toBe(9000)
        ->and(StorageDriver::SEAWEEDFS->port())->toBe(8333)
        ->and(StorageDriver::GARAGE->port())->toBe(3900);
});

test('storage driver console ports', function () {
    expect(StorageDriver::MINIO->consolePort())->toBe(9001)
        ->and(StorageDriver::SEAWEEDFS->consolePort())->toBe(8888)
        ->and(StorageDriver::GARAGE->consolePort())->toBe(3902);
});

test('storage driver select options are valid', function () {
    $options = StorageDriver::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('minio', 'MinIO (Classic)');
});
