<?php

use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\LaraKubeOutput;

class ViteHardenHelper
{
    use GeneratesProjectInfrastructure, LaraKubeOutput;

    public function laraKubeInfo(string $message): void {}
}

test('Vite Hardening: Strips Wayfinder and injects K8s config', function () {
    $tempDir = sys_get_temp_dir().'/vite-harden-test-'.uniqid();
    mkdir($tempDir, 0755, true);

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

    // Verify Wayfinder is gone
    expect($result)->not->toContain('wayfinder');

    // Verify K8s config is injected
    expect($result)->toContain("origin: 'https://vite-test-app.dev.test'");
    expect($result)->toContain("host: 'vite-test-app.dev.test'");
    expect($result)->toContain('cors: true');

    exec('rm -rf '.escapeshellarg($tempDir));
});

test('Vite Hardening: Handles Inertia SSR disabling', function () {
    $tempDir = sys_get_temp_dir().'/vite-ssr-test-'.uniqid();
    mkdir($tempDir, 0755, true);

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

    exec('rm -rf '.escapeshellarg($tempDir));
});
