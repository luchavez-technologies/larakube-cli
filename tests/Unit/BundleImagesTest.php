<?php

use App\Data\ConfigData;
use App\Traits\AssemblesBundle;

function bundleAssembler(): object
{
    return new class
    {
        use AssemblesBundle;
    };
}

test('bundleImages derives the app image + every declared dependency, enum-driven', function (): void {
    $config = ConfigData::from([
        'name' => 'shop',
        'database' => 'postgres',
        'cacheDriver' => 'redis',
        'objectStorage' => 'minio',
    ]);

    $images = bundleAssembler()->bundleImages($config);

    expect($images['app'])->toBe('shop:latest')
        ->and($images['dependencies'])->toContain('traefik:v3.1')
        ->and(collect($images['dependencies'])->contains(fn ($i) => str_contains($i, 'postgres')))->toBeTrue()
        ->and(collect($images['dependencies'])->contains(fn ($i) => str_contains($i, 'valkey') || str_contains($i, 'redis')))->toBeTrue()
        ->and(collect($images['dependencies'])->contains(fn ($i) => str_contains($i, 'minio')))->toBeTrue();
});

test('a SQLite + database-cache project adds only the system image (no DB/cache service)', function (): void {
    $config = ConfigData::from(['name' => 'tiny', 'database' => 'sqlite', 'cacheDriver' => 'database']);

    expect(bundleAssembler()->bundleImages($config)['dependencies'])->toBe(['traefik:v3.1']);
});

test('imageTarName produces filesystem-safe tarball names', function (): void {
    $r = bundleAssembler();

    expect($r->imageTarName('redis:7.4'))->toBe('redis-7.4.tar')
        ->and($r->imageTarName('shop:latest'))->toBe('shop-latest.tar')
        ->and($r->imageTarName('minio/minio:RELEASE.2025-09-07T16-13-09Z'))->toBe('minio-minio-RELEASE.2025-09-07T16-13-09Z.tar');
});

test('bundle build app image tag follows {name}:{env}-latest, not :latest', function (): void {
    // AssemblesBundle::bundleImages() returns :latest as the base tag — Kustomize's
    // images: rewrite block in the local/production overlays rewrites it at apply time.
    // BundleBuildCommand::handle() overrides the app key to :{env}-latest before
    // calling docker build and docker save, so local and production bundles never
    // collide on the same Docker daemon.
    $config = ConfigData::from(['name' => 'shop']);
    $env = 'airgap';

    $images = bundleAssembler()->bundleImages($config);
    $images['app'] = $config->getName().':'.$env.'-latest'; // what the command does

    expect($images['app'])->toBe('shop:airgap-latest')
        ->and($images['app'])->not->toBe('shop:latest');
});

test('bundleManifest records app, env, arch and the deduped image list', function (): void {
    $manifest = bundleAssembler()->bundleManifest(
        ConfigData::from(['name' => 'shop']), 'production', 'amd64', ['shop:latest', 'redis:7.4', 'redis:7.4'],
    );

    expect($manifest)->toMatchArray([
        'app' => 'shop',
        'environment' => 'production',
        'arch' => 'amd64',
        'images' => ['shop:latest', 'redis:7.4'],
    ])->and($manifest)->toHaveKey('createdAt');
});
