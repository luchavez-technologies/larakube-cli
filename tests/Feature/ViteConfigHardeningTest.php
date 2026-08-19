<?php

use App\Data\ConfigData;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Tests\Feature\ViteHardenHelper;

test('Vite Hardening: Preserves Wayfinder and injects K8s config', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $viteConfig = <<<'TS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { wayfinder } from 'wayfinder-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        wayfinder(),
    ],
});
TS;

    file_put_contents($tempDir.'/vite.config.ts', $viteConfig);

    $config = new ConfigData(name: 'test-app');
    $config->setPath($tempDir);
    $config->setIsScaffolding(true);

    (new ViteHardenHelper)->hardenViteConfig($config);

    $result = file_get_contents($tempDir.'/vite.config.ts');

    // Wayfinder must be preserved — the build runs in the PHP image now, so the
    // Vite plugin generates the route/action/form files itself.
    expect($result)->toContain('wayfinder');

    // Verify K8s config is injected
    expect($result)->toContain("origin: 'https://vite.test-app.kube'");
    expect($result)->toContain("host: 'vite.test-app.kube'")
        ->toContain('cors: true');

    $temporaryDirectory->delete();
});

test('Vite Hardening: Re-aligns a managed server block to the current TLD', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $viteConfig = <<<'TS'
import { defineConfig } from 'vite';

export default defineConfig({
server: {
        cors: true,
        origin: 'https://vite.test-app.kube',
        hmr: {
            host: 'vite.test-app.kube',
        },
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        watch: {
            ignored: ['**/.infrastructure/volume_data/**'],
        },
    },
    plugins: [],
});
TS;

    file_put_contents($tempDir.'/vite.config.ts', $viteConfig);

    // Simulate the TLD having changed (e.g. via `config:tld`) since this
    // project was scaffolded under the old 'kube' TLD.
    $globalConfigDir = $_SERVER['HOME'].'/.larakube';
    if (! is_dir($globalConfigDir)) {
        mkdir($globalConfigDir, 0700, true);
    }
    file_put_contents($globalConfigDir.'/config.json', json_encode(['localTld' => 'test']));

    $config = new ConfigData(name: 'test-app');
    $config->setPath($tempDir);

    (new ViteHardenHelper)->hardenViteConfig($config);

    $result = file_get_contents($tempDir.'/vite.config.ts');

    // A managed server block must be re-aligned to the new TLD, not left
    // stale with only an advisory.
    expect($result)->toContain("origin: 'https://vite.test-app.test'")
        ->and($result)->toContain("host: 'vite.test-app.test'");

    $temporaryDirectory->delete();
    unlink($globalConfigDir.'/config.json');
});

test('Vite Hardening: Handles Inertia SSR disabling', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $viteConfig = <<<'TS'
import { defineConfig } from 'vite';
import inertia from '@inertiajs/vite';

export default defineConfig({
    plugins: [
        inertia(),
    ],
});
TS;

    file_put_contents($tempDir.'/vite.config.ts', $viteConfig);

    $config = new ConfigData(name: 'test-app');
    $config->setPath($tempDir);
    $config->setIsScaffolding(true);

    (new ViteHardenHelper)->hardenViteConfig($config);

    $result = file_get_contents($tempDir.'/vite.config.ts');

    // Verify Inertia SSR is disabled
    expect($result)->toContain('inertia({ ssr: false })');

    $temporaryDirectory->delete();
});
