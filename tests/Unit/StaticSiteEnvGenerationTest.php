<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;

function staticEnvConfig(?AppFramework $framework): ConfigData
{
    $config = new ConfigData(id: 'demo', name: 'demo', path: '/tmp/demo', framework: $framework);
    $config->setEnvironments(['local', 'production']);

    return $config;
}

test('a static site gets no Laravel application variables', function (): void {
    $config = staticEnvConfig(AppFramework::VITE);

    // Nothing reads these in a static project — not Vite, not Caddy, not the
    // compiled bundle. Emitting them makes .env.production look configured
    // when it is only noisy.
    expect($config->getAllPublicEnvironmentVariables('production'))->toBeEmpty()
        ->and($config->getAllPublicEnvironmentVariables('local'))->toBeEmpty()
        ->and($config->getAllEnvironmentVariables('production'))->toBeEmpty();
});

test('a static site is never given secrets', function (): void {
    // A bundler that later gained a VITE_-prefixed alias would compile a
    // secret straight into a public asset.
    expect(staticEnvConfig(AppFramework::VITE)->getAllSecretEnvironmentVariables('production'))->toBeEmpty()
        ->and(staticEnvConfig(AppFramework::ASTRO)->getAllSecretEnvironmentVariables('local'))->toBeEmpty()
        ->and(staticEnvConfig(AppFramework::DOCUSAURUS)->getAllSecretEnvironmentVariables('production'))->toBeEmpty();
});

test('Laravel still gets its full environment', function (): void {
    $envs = staticEnvConfig(AppFramework::LARAVEL)->getAllPublicEnvironmentVariables('production');

    expect($envs)->toHaveKeys(['APP_URL', 'ASSET_URL'])
        // Hardcoded 'production' for every cloud env, since Laravel keys its
        // safeguards on App::environment('production').
        ->and($envs['APP_ENV'])->toBe('production')
        ->and($envs['APP_DEBUG'])->toBe('false');
});

test('a project with no declared framework is treated as Laravel, not static', function (): void {
    // framework is nullable and defaults to Laravel behaviour for
    // backwards-compatibility; it must not silently become an empty env.
    expect(staticEnvConfig(null)->getAllPublicEnvironmentVariables('production'))
        ->toHaveKey('APP_URL');
});
