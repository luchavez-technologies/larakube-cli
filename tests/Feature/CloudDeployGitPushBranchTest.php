<?php

/**
 * cloud:deploy's "prefer Git push instead?" shortcut (hasGithubActions() &&
 * interactive) used to exit immediately without ever regenerating the K8s
 * manifests from the blueprint — CI only applies whatever's already
 * committed, so taking this shortcut right after editing .larakube.json
 * could ship the previous configuration. Now it regenerates the manifests
 * and warns if .infrastructure/.larakube.json are left uncommitted.
 */

use App\Data\ConfigData;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function saveGitPushBranchConfig(string $dir): ConfigData
{
    $config = ConfigData::from([
        'name' => 'gitpushtest',
        'database' => 'sqlite',
        'framework' => 'laravel',
        'serverVariation' => 'fpm-nginx',
        'githubActions' => true,
        'environments' => ['local' => [], 'production' => []],
    ]);
    $config->setPath($dir);
    $config->saveToFile($dir);

    return $config;
}

beforeEach(function (): void {
    Prompt::interactive(true);

    $this->temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $this->tempDir = $this->temporaryDirectory->path();

    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function (): void {
    chdir($this->originalDir);
    $this->temporaryDirectory->delete();
    gc_collect_cycles();
});

test('preferring Git push regenerates manifests and warns when they are left uncommitted', function (): void {
    saveGitPushBranchConfig($this->tempDir);

    Process::fake([
        '*git*status*porcelain*' => Process::result(output: " M .infrastructure/k8s/overlays/production/ingress-patch.yaml\n"),
        '*kubectl *' => '',
    ]);

    $this->artisan('cloud:deploy', ['environment' => 'production'])
        ->expectsConfirmation('Would you prefer to trigger a deployment via Git push instead?', 'yes')
        ->expectsOutputToContain('Commit and push these before (or with) your deploy')
        ->assertExitCode(0);

    expect(file_exists($this->tempDir.'/.infrastructure/k8s/overlays/production/ingress-patch.yaml'))->toBeTrue();
});

test('preferring Git push stays quiet when the regenerated manifests are already committed', function (): void {
    saveGitPushBranchConfig($this->tempDir);

    Process::fake([
        '*git*status*porcelain*' => Process::result(output: ''),
        '*kubectl *' => '',
    ]);

    $this->artisan('cloud:deploy', ['environment' => 'production'])
        ->expectsConfirmation('Would you prefer to trigger a deployment via Git push instead?', 'yes')
        ->doesntExpectOutputToContain('Commit and push these')
        ->assertExitCode(0);
});
