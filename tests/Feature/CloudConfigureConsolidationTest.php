<?php

/**
 * cloud:configure absorbed cloud:configure:base|gha|gitlab|registry (clean
 * break, per plans/active/paas-core-expansion.md §7.2 — mechanical merge
 * only, --platform/--registry axis deferred). Covers:
 *  1. --only dispatch (registry/hosts/ci/invalid) without touching a real cluster.
 *  2. configureBase()'s auto-create completeness gap (.env.{env} + gitignore +
 *     DNA for a brand-new environment) — the fix from this session.
 *  3. The deploy-target overwrite guard — an existing target is left alone
 *     unless the user explicitly confirms reconfiguring it.
 *  4. detectCiPlatform()'s git-remote-based GitHub/GitLab dispatch.
 *
 * Prompt::interactive(false) means confirm()/text() return their `default`
 * value — so a required text() prompt with no default (promptCloudTarget's
 * "Server IP" step, reached only when there's no existing target AND no
 * kube-contexts available) throws NonInteractiveValidationException. Tests
 * that exercise a brand-new environment catch that and assert what happened
 * BEFORE that point, which is exactly the behavior under test.
 */

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;
use Laravel\Prompts\Prompt;

/**
 * Same trait stack the real cloud:configure:* commands compose (per
 * ConfiguresCloudEnvironment's own docblock), exposing configureBase() and
 * detectCiPlatform() for direct testing. $gitRemote overrides gitRemoteUrl()
 * so detectCiPlatform() can be tested against a simulated remote — no real
 * git binary/repo needed.
 */
function configuresCloudEnvironmentRunner(string $gitRemote = ''): object
{
    return new class($gitRemote)
    {
        use ConfiguresCloudEnvironment, GeneratesProjectInfrastructure, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

        public function __construct(private string $gitRemote = '') {}

        public function run(?string $environment): int
        {
            return $this->configureBase($environment);
        }

        public function detect(): string
        {
            return $this->detectCiPlatform();
        }

        protected function gitRemoteUrl(): string
        {
            return $this->gitRemote;
        }
    };
}

beforeEach(function () {
    Prompt::interactive(false);

    $this->tempDir = sys_get_temp_dir().'/larakube-cloudconfigure-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalDir);
    exec('rm -rf '.escapeshellarg($this->tempDir));
});

function saveConsolidationConfig(string $dir, array $environments): ConfigData
{
    $config = ConfigData::from([
        'name' => 'consoltest',
        'database' => 'sqlite',
        'environments' => $environments,
    ]);
    $config->setPath($dir);
    $config->saveToFile($dir);

    return $config;
}

test('--only=registry errors cleanly on an environment not yet in the blueprint, without touching a cluster', function () {
    saveConsolidationConfig($this->tempDir, ['local' => []]);

    $this->artisan('cloud:configure', ['environment' => 'production', '--only' => 'registry'])
        ->assertExitCode(1)
        ->expectsOutputToContain('is not in your blueprint');
});

test('--only=hosts errors cleanly on an environment not yet in the blueprint', function () {
    saveConsolidationConfig($this->tempDir, ['local' => []]);

    $this->artisan('cloud:configure', ['environment' => 'production', '--only' => 'hosts'])
        ->assertExitCode(1)
        ->expectsOutputToContain('is not in your blueprint');
});

test('an unknown --only value errors cleanly instead of silently running the guided flow', function () {
    saveConsolidationConfig($this->tempDir, ['local' => [], 'production' => []]);

    $this->artisan('cloud:configure', ['environment' => 'production', '--only' => 'bogus'])
        ->assertExitCode(1)
        ->expectsOutputToContain('Unknown --only value');
});

test('--only=hosts leaves a real, already-set host untouched', function () {
    $config = saveConsolidationConfig($this->tempDir, ['local' => [], 'production' => []]);
    $config->setHost('production', 'web', 'consoltest.example.com');
    $config->saveToFile($this->tempDir);

    $this->artisan('cloud:configure', ['environment' => 'production', '--only' => 'hosts'])
        ->assertExitCode(0);

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    expect($reloaded->getHost('production', 'web'))->toBe('consoltest.example.com');
});

test('configureBase seeds .env.{env}, gitignores it, and gathers DNA for a brand-new environment before the deploy-target prompt', function () {
    file_put_contents($this->tempDir.'/.env', "APP_NAME=consoltest\n");
    file_put_contents($this->tempDir.'/.gitignore', "vendor/\n");
    saveConsolidationConfig($this->tempDir, ['local' => []]);

    $runner = configuresCloudEnvironmentRunner();

    try {
        $runner->run('production');
    } catch (NonInteractiveValidationException) {
        // Expected: no kube-contexts in this sandbox, no existing target, so
        // promptCloudTarget() falls through to the required "Server IP" text
        // prompt with no default. Everything BEFORE that point already ran.
    }

    expect(file_exists($this->tempDir.'/.env.production'))->toBeTrue();
    expect(file_get_contents($this->tempDir.'/.env.production'))->toContain('APP_NAME=consoltest');

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');
    expect($gitignore)->toContain('.env.*');

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    expect($reloaded->hasEnvironment('production'))->toBeTrue();
    expect($reloaded->getIngress('production'))->not->toBeNull();
});

test('re-running configureBase on an env with an existing deploy target does NOT overwrite it (non-interactive confirm defaults to false)', function () {
    $config = saveConsolidationConfig($this->tempDir, ['local' => [], 'production' => []]);
    $config->setCloud('production', new CloudData(ip: '203.0.113.10', user: 'deploy'));
    $config->setHost('production', 'web', 'consoltest.example.com');
    $config->saveToFile($this->tempDir);

    $runner = configuresCloudEnvironmentRunner();

    // Must complete WITHOUT throwing — proves promptCloudTarget() was never
    // reached, because the overwrite-guard confirm() (default false) skipped it.
    expect($runner->run('production'))->toBe(0);

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    expect($reloaded->getCloudIp('production'))->toBe('203.0.113.10')
        ->and($reloaded->getCloudUser('production'))->toBe('deploy');
});

test('detectCiPlatform defaults to github when there is no git remote at all', function () {
    expect(configuresCloudEnvironmentRunner('')->detect())->toBe('github');
});

test('detectCiPlatform routes a gitlab.com remote to gitlab, and anything else to github', function () {
    expect(configuresCloudEnvironmentRunner('git@gitlab.com:acme/consoltest.git')->detect())->toBe('gitlab')
        ->and(configuresCloudEnvironmentRunner('git@github.com:acme/consoltest.git')->detect())->toBe('github')
        ->and(configuresCloudEnvironmentRunner('https://gitlab.com/acme/consoltest')->detect())->toBe('gitlab');
});
