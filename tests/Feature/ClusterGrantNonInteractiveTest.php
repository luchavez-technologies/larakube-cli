<?php

/**
 * cluster:grant headless behavior — LaraKube Cloud drives this to mint a
 * scoped teammate kubeconfig from a job container (§5 of larakube-cloud.md,
 * Teams/RBAC). Covers the non-interactive guards that must fail clearly
 * instead of hanging on a required prompt, and the --json wrapper's failure
 * shape. The full grant flow needs a real cluster + kubectl (the trait
 * helpers it uses are unit-tested in TeammateRbacTest); these tests exercise
 * the pre-kubectl guards only.
 */

use App\State;
use Laravel\Prompts\Prompt;

beforeEach(function () {
    Prompt::interactive(false);

    // Run outside any project so resolveClusterTarget takes the standalone
    // (literal namespace + --context) branch — the path a Cloud job uses.
    $this->tempDir = sys_get_temp_dir().'/larakube-clustergrant-ni-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalDir);
    exec('rm -rf '.escapeshellarg($this->tempDir));
});

test('a missing --name fails clearly under --no-interaction instead of prompting', function () {
    $this->artisan('cluster:grant', [
        'environment' => 'blue-production',
        '--context' => 'do-nyc1-blue',
        '--no-interaction' => true,
    ])->assertExitCode(1);

    expect(State::$lastError)->toContain('--name=');
});

test('a missing namespace/context target fails clearly, not with a hang', function () {
    // No environment arg and no project → standalone branch with nothing to
    // target. Must error, not prompt.
    $this->artisan('cluster:grant', ['--name' => 'lloyd', '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::$lastError)->toContain('namespace');
});

test('--json on a failing grant emits one parseable failure object', function () {
    $this->artisan('cluster:grant', [
        'environment' => 'blue-production',
        '--context' => 'do-nyc1-blue',
        '--json' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('"success":false')
        ->assertExitCode(1);
});
