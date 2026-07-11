<?php

use Illuminate\Support\Facades\Process;

test('pipeline:test guides installation when act is missing', function () {
    Process::fake([
        'which act' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('pipeline:test production')
        ->assertExitCode(1)
        ->expectsOutputToContain("The 'act' CLI tool is required to run workflows locally.")
        ->expectsOutputToContain('Install it via Homebrew: brew install nektos/tap/act');
});

test('pipeline:test fails when docker is not running', function () {
    Process::fake([
        'which act' => '/usr/local/bin/act',
        'docker info' => Process::result(output: 'error', exitCode: 1),
    ]);

    $this->artisan('pipeline:test production')
        ->assertExitCode(1)
        ->expectsOutputToContain('Docker daemon is not running. Please start Docker and try again.');
});

test('pipeline:test runs act builder locally with mock secrets', function () {
    $tempDir = sys_get_temp_dir().'/larakube-pipe-test-'.uniqid();
    mkdir($tempDir, 0755, true);
    $tempDir = realpath($tempDir) ?: $tempDir;

    // Create a .larakube.json
    file_put_contents($tempDir.'/.larakube.json', json_encode(['name' => 'demo']));
    file_put_contents($tempDir.'/.env.production', "APP_NAME=demotest\n");

    mkdir($tempDir.'/.github/workflows', 0755, true);
    file_put_contents($tempDir.'/.github/workflows/larakube-deploy-production.yml', "on:\n  push:\n    branches: [ main ]\n");

    Process::fake([
        'which act' => '/usr/local/bin/act',
        'docker info' => Process::result(output: 'running', exitCode: 0),
        'kubectl *' => Process::result(output: 'success', exitCode: 0),
        '*' => Process::result(output: 'act success', exitCode: 0),
    ]);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('pipeline:test production --dry-run')
            ->assertExitCode(0)
            ->expectsOutputToContain("Executing local 'act' runner for job 'build'...")
            ->expectsOutputToContain('Local act workflow completed successfully.');

        Process::assertRan(fn ($process) => str_contains($process->command, 'act') && str_contains($process->command, '--secret-file'));
    } finally {
        chdir($originalDir);
        exec('rm -rf '.escapeshellarg($tempDir));
    }
});
