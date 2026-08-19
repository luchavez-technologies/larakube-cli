<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Tests\Feature\EnvSyncHelper;

/**
 * syncEnvFile used to be production/local-binary (it only ever wrote .env or
 * .env.production), so non-production cloud envs (staging, preview, …) were never
 * generated. These lock in that any cloud env targets its own .env.<environment>,
 * and that a local sync seeds EVERY configured cloud env, not just production.
 */
function configWithEnvs(array $names): ConfigData
{
    $envs = [];
    foreach ($names as $n) {
        $envs[$n] = new EnvironmentData;
    }

    return new ConfigData(name: 'test-app', environments: $envs);
}

test('syncEnvFile targets .env.<environment> for ANY cloud env (e.g. staging)', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    file_put_contents($dir.'/.env', "APP_NAME=Test\nASSET_URL=https://app.kube\n");

    (new EnvSyncHelper(configWithEnvs(['local', 'staging'])))
        ->sync($dir, ['APP_URL' => 'https://staging.app.com'], false, 'staging');

    // .env.staging seeded from .env, with APP_URL applied; base .env untouched.
    expect(file_get_contents($dir.'/.env.staging'))->toContain('APP_URL=https://staging.app.com')
        ->and(file_get_contents($dir.'/.env'))->not->toContain('staging.app.com');

    $temporaryDirectory->delete();
});

test('a local sync seeds EVERY configured cloud env file, not just production', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    file_put_contents($dir.'/.env', "APP_NAME=Test\n");

    (new EnvSyncHelper(configWithEnvs(['local', 'production', 'staging'])))
        ->sync($dir, ['APP_KEY' => 'base64:abc']);

    expect(file_get_contents($dir.'/.env'))->toContain('APP_KEY=base64:abc')
        ->and(file_exists($dir.'/.env.production'))->toBeTrue()
        ->and(file_exists($dir.'/.env.staging'))->toBeTrue()
        // seeded content carries the local update
        ->and(file_get_contents($dir.'/.env.staging'))->toContain('APP_KEY=base64:abc');

    $temporaryDirectory->delete();
});
