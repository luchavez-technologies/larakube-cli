<?php

use App\Enums\AppFramework;
use App\Enums\ClusterTool;
use App\Enums\StorageDriver;
use App\Services\Scaffolding\FrameworkCatalog;
use App\Services\Scaffolding\ServerStack;
use App\Traits\SupportedDriversTrait;

test('storage driver has correct labels', function (): void {
    expect(StorageDriver::SEAWEEDFS->getLabel())->toBe('SeaweedFS (High Performance)')
        ->and(StorageDriver::GARAGE->getLabel())->toBe('Garage (Modern/Rust)');
});

test('storage driver ports', function (): void {
    expect(StorageDriver::SEAWEEDFS->port())->toBe(8333)
        ->and(StorageDriver::GARAGE->port())->toBe(3900);
});

test('storage driver console ports', function (): void {
    expect(StorageDriver::SEAWEEDFS->consolePort())->toBe(8888)
        ->and(StorageDriver::GARAGE->consolePort())->toBe(3902);
});

test('storage driver select options are valid', function (): void {
    $options = StorageDriver::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('seaweedfs', 'SeaweedFS (High Performance)')
        ->and($options)->not->toHaveKey('minio');
});

test('minio is not a valid storage driver and no framework or tool offers it', function (): void {
    expect(StorageDriver::tryFrom('minio'))->toBeNull()
        ->and(array_map(fn (StorageDriver $d) => $d->value, ServerStack::storages()))->not->toContain('minio');

    $resolver = new class
    {
        use SupportedDriversTrait;
    };

    foreach (AppFramework::cases() as $framework) {
        expect(array_map(fn (StorageDriver $d) => $d->value, $framework->supportedStorageDrivers()))->not->toContain('minio')
            ->and(array_map(fn (StorageDriver $d) => $d->value, $resolver->getSupportedStorageDrivers($framework)))->not->toContain('minio');
    }

    foreach ((new FrameworkCatalog)->all() as $entry) {
        foreach ($entry['fields'] ?? [] as $field) {
            if (($field['key'] ?? null) === 'storage') {
                expect($field['default'] ?? null)->not->toBe('minio');
                $values = array_column($field['options'] ?? [], 'value');
                expect($values)->not->toContain('minio');
            }
        }
    }

    foreach (ClusterTool::cases() as $tool) {
        expect(array_map(fn (StorageDriver $d) => $d->value, $resolver->getSupportedStorageDrivers($tool)))->not->toContain('minio');
    }
});
