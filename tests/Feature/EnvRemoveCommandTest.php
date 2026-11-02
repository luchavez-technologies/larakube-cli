<?php

use App\Data\CloudData;
use App\Data\ConfigData;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * The counterpart to env's creation tests — env:remove drops an environment
 * from the blueprint (.larakube.json + .larakube.local.json) and prunes its
 * now-orphaned k8s/overlays/{env} directory, without touching any live
 * server or cluster.
 */
function envRemoveProject(array $environments): array
{
    $config = ConfigData::from([
        'name' => 'envremove',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'environments' => $environments,
    ]);

    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    $config->setPath($tempDir);
    $config->saveToFile($tempDir);

    return [$config, $tempDir, $temporaryDirectory];
}

test('env:remove --force deletes a cloud environment from the blueprint and prunes its overlay', function (): void {
    [$config, $tempDir, $temporaryDirectory] = envRemoveProject([
        'local' => [],
        'production' => ['hosts' => ['web' => 'prod.example.com']],
    ]);
    $config->setCloud('production', new CloudData(ip: '203.0.113.21', provider: 'do'));
    $config->saveToFile($tempDir);

    // A standalone overlay directory on disk, as `env`/`up`/`heal` would have
    // generated it — env:remove must prune it via the same
    // pruneStaleManifests() every scaffolding pass already runs.
    $overlayPath = "{$tempDir}/.infrastructure/k8s/overlays/production";
    mkdir($overlayPath, 0755, true);
    file_put_contents("{$overlayPath}/kustomization.yaml", "kind: Kustomization\n");

    // The user's actual local env values for this environment — must survive
    // removal untouched. env:remove only drops the blueprint entry + its
    // generated k8s manifests, never a .env.{environment} file.
    file_put_contents("{$tempDir}/.env.production", "APP_ENV=production\nDB_PASSWORD=super-secret\n");

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        expect($overlayPath)->toBeDirectory();

        $this->artisan('env:remove production --force')
            ->expectsOutputToContain("'production' removed from this project's blueprint")
            ->expectsOutputToContain('.env.production was left in place')
            ->assertExitCode(0);

        $reloaded = ConfigData::loadFromFile($tempDir);
        expect($reloaded->hasEnvironment('production'))->toBeFalse()
            ->and($overlayPath)->not->toBeDirectory()
            ->and("{$tempDir}/.env.production")->toBeFile()
            ->and(file_get_contents("{$tempDir}/.env.production"))->toBe("APP_ENV=production\nDB_PASSWORD=super-secret\n");
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});

test('env:remove requires typing confirm interactively unless --force is passed', function (): void {
    [$config, $tempDir, $temporaryDirectory] = envRemoveProject([
        'local' => [],
        'staging' => [],
    ]);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('env:remove staging')
            ->expectsOutputToContain("'staging' will be REMOVED")
            ->expectsQuestion('Type confirm to proceed', 'nope')
            ->expectsOutputToContain('Aborted.')
            ->assertExitCode(0);

        expect(ConfigData::loadFromFile($tempDir)->hasEnvironment('staging'))->toBeTrue();

        $this->artisan('env:remove staging')
            ->expectsQuestion('Type confirm to proceed', 'confirm')
            ->assertExitCode(0);

        expect(ConfigData::loadFromFile($tempDir)->hasEnvironment('staging'))->toBeFalse();
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});

test("env:remove refuses to remove 'local'", function (): void {
    [$config, $tempDir, $temporaryDirectory] = envRemoveProject(['local' => [], 'production' => []]);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('env:remove local --force')
            ->expectsOutputToContain("'local' isn't a removable cloud environment")
            ->assertExitCode(1);

        expect(ConfigData::loadFromFile($tempDir)->hasEnvironment('local'))->toBeTrue();
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});

test('env:remove errors on an environment not in the blueprint', function (): void {
    [$config, $tempDir, $temporaryDirectory] = envRemoveProject(['local' => [], 'production' => []]);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('env:remove ghost --force')
            ->expectsOutputToContain("Environment 'ghost' is not in this project's blueprint")
            ->assertExitCode(1);
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});

test('env:remove with no environments to remove is a clean no-op', function (): void {
    [$config, $tempDir, $temporaryDirectory] = envRemoveProject(['local' => []]);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('env:remove --force')
            ->expectsOutputToContain('no cloud environments to remove')
            ->assertExitCode(0);
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});

test('non-interactive env:remove with no environment argument fails loudly instead of hanging', function (): void {
    [$config, $tempDir, $temporaryDirectory] = envRemoveProject(['local' => [], 'production' => []]);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('env:remove --no-interaction --force')->run();
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
})->throws(InvalidArgumentException::class, 'Pass the environment to remove');
