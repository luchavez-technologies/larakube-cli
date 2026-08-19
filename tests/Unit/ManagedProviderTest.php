<?php

use App\Enums\ManagedProvider;

test('each managed provider maps to its default storage class', function (): void {
    expect(ManagedProvider::DOKS->defaultStorageClass())->toBe('do-block-storage')
        ->and(ManagedProvider::EKS->defaultStorageClass())->toBe('gp3')
        ->and(ManagedProvider::GKE->defaultStorageClass())->toBe('standard')
        ->and(ManagedProvider::AKS->defaultStorageClass())->toBe('managed-csi')
        ->and(ManagedProvider::CIVO->defaultStorageClass())->toBe('civo-volume')
        ->and(ManagedProvider::LKE->defaultStorageClass())->toBe('linode-block-storage')
        ->and(ManagedProvider::CUSTOM->defaultStorageClass())->toBeNull();
});
