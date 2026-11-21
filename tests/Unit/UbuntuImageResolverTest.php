<?php

use App\Services\Cloud\UbuntuImageResolver;

test('resolves ubuntu 24.04 noble numbat image configs for all providers', function (): void {
    expect(UbuntuImageResolver::resolve('hetzner'))->toBe(['image' => 'ubuntu-24.04'])
        ->and(UbuntuImageResolver::resolve('do'))->toBe(['image' => 'ubuntu-24-04-x64'])
        ->and(UbuntuImageResolver::resolve('gcp'))->toBe(['image' => 'ubuntu-os-cloud/ubuntu-2404-lts-amd64'])
        ->and(UbuntuImageResolver::resolve('aws'))->toBe(['amiPattern' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*']);
});

test('returns empty array for unknown provider', function (): void {
    expect(UbuntuImageResolver::resolve('unknown'))->toBeEmpty();
});
