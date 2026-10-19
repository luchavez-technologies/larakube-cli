<?php

use App\Services\Cloud\UbuntuImageResolver;

test('resolves default ubuntu 24.04 image configs for all providers', function (): void {
    expect(UbuntuImageResolver::resolve('hetzner'))->toBe(['image' => 'ubuntu-24.04'])
        ->and(UbuntuImageResolver::resolve('do'))->toBe(['image' => 'ubuntu-24-04-x64'])
        ->and(UbuntuImageResolver::resolve('gcp'))->toBe(['image' => 'ubuntu-os-cloud/ubuntu-2404-lts-amd64'])
        ->and(UbuntuImageResolver::resolve('aws'))->toBe(['amiPattern' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*']);
});

test('resolves ubuntu 26.04 resolute raccoon image configs for all providers', function (): void {
    expect(UbuntuImageResolver::resolve('hetzner', '26.04'))->toBe(['image' => 'ubuntu-26.04'])
        ->and(UbuntuImageResolver::resolve('do', '26.04'))->toBe(['image' => 'ubuntu-26-04-x64'])
        ->and(UbuntuImageResolver::resolve('gcp', '26.04'))->toBe(['image' => 'ubuntu-os-cloud/ubuntu-2604-lts-amd64'])
        ->and(UbuntuImageResolver::resolve('aws', '26.04'))->toBe(['amiPattern' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-resolute-26.04-amd64-server-*']);
});

test('falls back to default 24.04 on unsupported version', function (): void {
    expect(UbuntuImageResolver::resolve('hetzner', '22.04'))->toBe(['image' => 'ubuntu-24.04'])
        ->and(UbuntuImageResolver::resolve('aws', 'invalid'))->toBe(['amiPattern' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*']);
});

test('returns empty array for unknown provider', function (): void {
    expect(UbuntuImageResolver::resolve('unknown'))->toBeEmpty();
});
