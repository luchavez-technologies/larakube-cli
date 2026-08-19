<?php

use App\Traits\InteractsWithPipelines;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function pipelineHelper(): object
{
    return new class
    {
        use InteractsWithPipelines {
            discoverWorkflows as public;
            parseWorkflowEnv as public;
            parseWorkflowTrigger as public;
            extractSecretsFromYaml as public;
            getActPath as public;
        }
    };
}

test('parseWorkflowEnv extracts environment name correctly', function (): void {
    $helper = pipelineHelper();
    expect($helper->parseWorkflowEnv('larakube-deploy-production.yml'))->toBe('production')
        ->and($helper->parseWorkflowEnv('larakube-deploy-staging.yml'))->toBe('staging')
        ->and($helper->parseWorkflowEnv('other-file.yml'))->toBeNull();
});

test('discoverWorkflows scans for generated workflows', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = realpath($temporaryDirectory->path()) ?: $temporaryDirectory->path();

    mkdir($tempDir.'/.github/workflows', 0755, true);
    file_put_contents($tempDir.'/.github/workflows/larakube-deploy-production.yml', 'on: push');

    $helper = pipelineHelper();
    $discovered = $helper->discoverWorkflows($tempDir);

    expect($discovered)->toHaveCount(1)
        ->and($discovered[0]['platform'])->toBe('github')
        ->and($discovered[0]['file'])->toBe('.github/workflows/larakube-deploy-production.yml')
        ->and($discovered[0]['env'])->toBe('production');

    $temporaryDirectory->delete();
});

test('parseWorkflowTrigger resolves triggers from workflow YAML', function (): void {
    $helper = pipelineHelper();

    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();

    // GitLab CI trigger
    $tempGitlab = $temporaryDirectory->path('.gitlab-ci.yml');
    file_put_contents($tempGitlab, "stages:\n  - deploy\n");
    expect($helper->parseWorkflowTrigger($tempGitlab))->toBe('push');

    // GitHub/Gitea mock files
    $tempGha = $temporaryDirectory->path('mock-gha.yml');

    // Single string trigger
    file_put_contents($tempGha, "on: push\n");
    expect($helper->parseWorkflowTrigger($tempGha))->toBe('push');

    // Complex push branch trigger
    file_put_contents($tempGha, "on:\n  push:\n    branches: [ main, dev ]\n");
    expect($helper->parseWorkflowTrigger($tempGha))->toBe('push (main, dev)');

    $temporaryDirectory->delete();
});

test('extractSecretsFromYaml retrieves secret variables', function (): void {
    $helper = pipelineHelper();

    $yaml = [
        'env' => [
            'KUBE' => '${{ secrets.PRODUCTION_KUBECONFIG }}',
            'ENV' => '${{ secrets.PRODUCTION_ENV_FILE_BASE64 }}',
        ],
        'jobs' => [
            'deploy' => [
                'steps' => [
                    ['run' => 'echo $GL_KUBECONFIG'],
                ],
            ],
        ],
    ];

    $secrets = $helper->extractSecretsFromYaml($yaml);

    expect($secrets)->toContain('PRODUCTION_KUBECONFIG')
        ->and($secrets)->toContain('PRODUCTION_ENV_FILE_BASE64')
        ->and($secrets)->toContain('GL_KUBECONFIG');
});

test('getActPath returns absolute path or null based on execution check', function (): void {
    Process::fake(['which act' => '/usr/local/bin/act']);
    expect(pipelineHelper()->getActPath())->toBe('/usr/local/bin/act');

    Process::fake(['which act' => Process::result(output: '', exitCode: 1)]);
    expect(pipelineHelper()->getActPath())->toBeNull();
});
