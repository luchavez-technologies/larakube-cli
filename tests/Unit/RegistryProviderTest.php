<?php

use App\Enums\RegistryProvider;

test('RegistryProvider has GAR case with correct attributes', function (): void {
    $gar = RegistryProvider::GAR;

    expect($gar->value)->toBe('gar')
        ->and($gar->label())->toBe('Google Artifact Registry (GAR)')
        ->and($gar->registryHost())->toBe('docker.pkg.dev')
        ->and($gar->defaultImagePath('my-proj/repo/image'))->toBe('my-proj/repo/image')
        ->and($gar->isGitLabNative())->toBeFalse();
});

test('RegistryProvider cases include all 5 supported providers', function (): void {
    $cases = array_map(fn (RegistryProvider $p) => $p->value, RegistryProvider::cases());

    expect($cases)->toBe(['ghcr', 'dockerhub', 'gitlab', 'forgejo', 'gar']);
});
