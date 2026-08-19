<?php

use Spatie\TemporaryDirectory\TemporaryDirectory;
use Tests\Feature\ViteHardenHelper;

/**
 * cloud:deploy's domain sync only rewrote APP_URL, so a leaked local
 * ASSET_URL=https://app.dev.test or app.kube survived into production — @vite
 * then prefixed every asset with the dev host (404 / unstyled). alignAssetUrlValue()
 * fixes a stale/empty ASSET_URL while leaving a real CDN host (or an absent one) alone.
 */
function aligner(): ViteHardenHelper
{
    return new ViteHardenHelper;
}

test('rewrites a leaked local *.dev.test ASSET_URL to the production domain', function (): void {
    $env = "APP_URL=https://app-two.luchtech.dev\nASSET_URL=https://app-two.dev.test\n";

    expect(aligner()->alignAssetUrlValue($env, 'https://app-two.luchtech.dev'))
        ->toContain('ASSET_URL=https://app-two.luchtech.dev')
        ->not->toContain('dev.test');
});

test('fills an empty ASSET_URL', function (): void {
    expect(aligner()->alignAssetUrlValue("ASSET_URL=\n", 'https://app.luchtech.dev'))
        ->toContain('ASSET_URL=https://app.luchtech.dev');
});

test('uncomments and aligns a commented local ASSET_URL', function (): void {
    expect(aligner()->alignAssetUrlValue("#ASSET_URL=https://app.dev.test\n", 'https://app.luchtech.dev'))
        ->toContain('ASSET_URL=https://app.luchtech.dev');
});

test('never clobbers a deliberate CDN/asset host', function (): void {
    $env = "ASSET_URL=https://cdn.example.com\n";

    expect(aligner()->alignAssetUrlValue($env, 'https://app.luchtech.dev'))
        ->toBe($env); // unchanged
});

test('leaves an absent ASSET_URL alone (assets resolve relative to APP_URL)', function (): void {
    $env = "APP_URL=https://app.luchtech.dev\nDB_HOST=postgres\n";

    expect(aligner()->alignAssetUrlValue($env, 'https://app.luchtech.dev'))
        ->toBe($env); // unchanged — no ASSET_URL line added
});

test('aligns a NON-production env file too (.env.staging) — multi-environment', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    file_put_contents($dir.'/.env.staging', "APP_URL=https://staging.app.com\nASSET_URL=https://app.dev.test\n");

    aligner()->alignEnv($dir, 'staging', 'staging.app.com');

    expect(file_get_contents($dir.'/.env.staging'))
        ->toContain('ASSET_URL=https://staging.app.com')
        ->not->toContain('dev.test');

    $temporaryDirectory->delete();
});

test('skips local — the .kube host is correct there', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    $env = "ASSET_URL=https://app.kube\n";
    file_put_contents($dir.'/.env', $env);

    aligner()->alignEnv($dir, 'local', 'app.kube');

    // Untouched: local intentionally keeps the dev host, and no .env.local is written.
    expect(file_get_contents($dir.'/.env'))->toBe($env)
        ->and(file_exists($dir.'/.env.local'))->toBeFalse();

    $temporaryDirectory->delete();
});

test('rewrites a leaked local *.kube ASSET_URL to the production domain', function (): void {
    $env = "APP_URL=https://app-two.luchtech.dev\nASSET_URL=https://app-two.kube\n";

    expect(aligner()->alignAssetUrlValue($env, 'https://app-two.luchtech.dev'))
        ->toContain('ASSET_URL=https://app-two.luchtech.dev')
        ->not->toContain('.kube');
});
