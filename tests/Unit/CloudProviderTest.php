<?php

use App\Enums\CloudProvider;
use App\Enums\ManagedProvider;

test('cloud provider labels and managed provider mappings', function (): void {
    expect(CloudProvider::DO->label())->toBe('DigitalOcean')
        ->and(CloudProvider::GCP->label())->toBe('Google Cloud Platform')
        ->and(CloudProvider::AWS->label())->toBe('Amazon Web Services')
        ->and(CloudProvider::DO->managedProvider())->toBe(ManagedProvider::DOKS)
        ->and(CloudProvider::GCP->managedProvider())->toBe(ManagedProvider::GKE)
        ->and(CloudProvider::AWS->managedProvider())->toBe(ManagedProvider::EKS);
});

test('cloud provider active providers list contains DO, GCP, and AWS', function (): void {
    $active = CloudProvider::activeProviders();

    expect($active)->toHaveKey('do')
        ->and($active)->toHaveKey('gcp')
        ->and($active)->toHaveKey('aws')
        ->and($active['do'])->toBe('DigitalOcean')
        ->and($active['gcp'])->toBe('Google Cloud Platform')
        ->and($active['aws'])->toBe('Amazon Web Services');
});

test('cloud provider regions and defaults', function (): void {
    expect(CloudProvider::DO->defaultRegion())->toBe('nyc1')
        ->and(CloudProvider::GCP->defaultRegion())->toBe('us-central1')
        ->and(CloudProvider::GCP->regions())->toHaveKey('us-central1')
        ->and(CloudProvider::GCP->regions()['us-central1'])->toContain('Iowa')
        ->and(CloudProvider::AWS->defaultRegion())->toBe('us-east-1')
        ->and(CloudProvider::AWS->regions())->toHaveKey('us-east-1')
        ->and(CloudProvider::AWS->regions()['us-east-1'])->toContain('Virginia');
});

test('cloud provider machine sizes and defaults', function (): void {
    expect(CloudProvider::DO->defaultVpsSize())->toBe('s-1vcpu-1gb')
        ->and(CloudProvider::GCP->defaultVpsSize())->toBe('e2-medium')
        ->and(CloudProvider::GCP->vpsSizes())->toHaveKey('e2-micro')
        ->and(CloudProvider::GCP->vpsSizes())->toHaveKey('e2-medium')
        ->and(CloudProvider::GCP->defaultManagedSize())->toBe('e2-medium')
        ->and(CloudProvider::GCP->managedSizes())->toHaveKey('e2-medium')
        ->and(CloudProvider::AWS->defaultVpsSize())->toBe('t3.medium')
        ->and(CloudProvider::AWS->vpsSizes())->toHaveKey('t3.micro')
        ->and(CloudProvider::AWS->vpsSizes())->toHaveKey('t3.medium')
        ->and(CloudProvider::AWS->defaultManagedSize())->toBe('t3.medium')
        ->and(CloudProvider::AWS->managedSizes())->toHaveKey('t3.medium');
});
