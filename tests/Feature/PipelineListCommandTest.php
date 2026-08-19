<?php

use Illuminate\Support\Facades\Artisan;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('pipeline:list prints warning when no workflows exist', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = realpath($temporaryDirectory->path()) ?: $temporaryDirectory->path();

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('pipeline:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('No LaraKube workflows/pipelines found in this project.');
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});

test('pipeline:list lists discovered workflows in a table', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = realpath($temporaryDirectory->path()) ?: $temporaryDirectory->path();

    // Create github workflow directory
    mkdir($tempDir.'/.github/workflows', 0755, true);
    file_put_contents($tempDir.'/.github/workflows/larakube-deploy-production.yml', "on:\n  push:\n    branches: [ main ]\n");

    // Create gitea workflow directory
    mkdir($tempDir.'/.gitea/workflows', 0755, true);
    file_put_contents($tempDir.'/.gitea/workflows/larakube-deploy-staging.yml', "on: push\n");

    // Create gitlab CI file
    file_put_contents($tempDir.'/.gitlab-ci.yml', "stages:\n  - deploy\n");

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $exitCode = Artisan::call('pipeline:list');
        expect($exitCode)->toBe(0);

        $output = Artisan::output();
        expect($output)->toContain('GitHub Actions')
            ->and($output)->toContain('.github/workflows')
            ->and($output)->toContain('production')
            ->and($output)->toContain('push (main)')

            ->and($output)->toContain('Gitea Actions')
            ->and($output)->toContain('.gitea/workflows')
            ->and($output)->toContain('staging')
            ->and($output)->toContain('push')

            ->and($output)->toContain('GitLab CI/CD')
            ->and($output)->toContain('.gitlab-ci.yml')
            ->and($output)->toContain('all');
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});
