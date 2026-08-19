<?php

/**
 * writeTenantConfig() picks the env file the deploy target actually reads:
 * local → .env (the hostPath-mounted pod loads it), cloud envs → .env.{env}.
 * Pure file I/O (no cluster), so it lives here rather than a smoke test.
 */

use App\Commands\Plex\PlexJoinCommand;
use App\Data\ConfigData;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** Subclass that silences output and exposes the protected writer. */
function tenantConfigWriter(): object
{
    return new class extends PlexJoinCommand
    {
        public function callWrite(string $projectPath, ConfigData $config, string $env, array $services): void
        {
            $this->writeTenantConfig($projectPath, $config, $env, 'demo_tenant', 'pw-secret-123', null, $services);
        }

        // Swallow the progress lines so the test output stays clean.
        public function line($string, $style = null, $verbosity = null): void {}
    };
}

function tmpProject(string $envContents = "APP_NAME=Demo\n"): TemporaryDirectory
{
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    file_put_contents($temporaryDirectory->path('.env'), $envContents);

    return $temporaryDirectory;
}

test('local join writes the Commons connection into .env (not .env.local)', function (): void {
    $temporaryDirectory = tmpProject();
    $dir = $temporaryDirectory->path();
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mysql']);

    tenantConfigWriter()->callWrite($dir, $config, 'local', ['mysql']);

    $env = (string) file_get_contents($dir.'/.env');

    expect($env)->toContain('DB_PASSWORD=pw-secret-123')
        ->and($env)->toContain('DB_USERNAME=demo_tenant')
        ->and($env)->toContain('APP_NAME=Demo')               // pre-existing keys preserved
        ->and(file_exists($dir.'/.env.local'))->toBeFalse();  // the bug we fixed

    $temporaryDirectory->delete();
});

test('cloud join writes .env.{env} and leaves .env untouched', function (): void {
    $temporaryDirectory = tmpProject();
    $dir = $temporaryDirectory->path();
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mysql']);

    tenantConfigWriter()->callWrite($dir, $config, 'production', ['mysql']);

    $prod = (string) file_get_contents($dir.'/.env.production');
    $base = (string) file_get_contents($dir.'/.env');

    expect($prod)->toContain('DB_PASSWORD=pw-secret-123')
        ->and($base)->not->toContain('DB_PASSWORD=pw-secret-123');

    $temporaryDirectory->delete();
});
