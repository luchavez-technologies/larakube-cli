<?php

use App\Traits\InteractsWithPipelines;
use Illuminate\Support\Facades\Process;

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

test('parseWorkflowEnv extracts environment name correctly', function () {
    $helper = pipelineHelper();
    expect($helper->parseWorkflowEnv('larakube-deploy-production.yml'))->toBe('production')
        ->and($helper->parseWorkflowEnv('larakube-deploy-staging.yml'))->toBe('staging')
        ->and($helper->parseWorkflowEnv('other-file.yml'))->toBeNull();
});

test('discoverWorkflows scans for generated workflows', function () {
    $tempDir = sys_get_temp_dir().'/larakube-test-disc-'.uniqid();
    mkdir($tempDir, 0755, true);
    $tempDir = realpath($tempDir) ?: $tempDir;

    mkdir($tempDir.'/.github/workflows', 0755, true);
    file_put_contents($tempDir.'/.github/workflows/larakube-deploy-production.yml', 'on: push');

    $helper = pipelineHelper();
    $discovered = $helper->discoverWorkflows($tempDir);

    expect($discovered)->toHaveCount(1)
        ->and($discovered[0]['platform'])->toBe('github')
        ->and($discovered[0]['file'])->toBe('.github/workflows/larakube-deploy-production.yml')
        ->and($discovered[0]['env'])->toBe('production');

    exec('rm -rf '.escapeshellarg($tempDir));
});

test('parseWorkflowTrigger resolves triggers from workflow YAML', function () {
    $helper = pipelineHelper();

    // GitLab CI trigger
    $tempDir = sys_get_temp_dir();
    $tempGitlab = $tempDir.'/.gitlab-ci.yml';
    file_put_contents($tempGitlab, "stages:\n  - deploy\n");
    expect($helper->parseWorkflowTrigger($tempGitlab))->toBe('push');
    @unlink($tempGitlab);

    // GitHub/Gitea mock files
    $tempGha = $tempDir.'/mock-gha.yml';

    // Single string trigger
    file_put_contents($tempGha, "on: push\n");
    expect($helper->parseWorkflowTrigger($tempGha))->toBe('push');

    // Complex push branch trigger
    file_put_contents($tempGha, "on:\n  push:\n    branches: [ main, dev ]\n");
    expect($helper->parseWorkflowTrigger($tempGha))->toBe('push (main, dev)');

    @unlink($tempGha);
});

test('extractSecretsFromYaml retrieves secret variables', function () {
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

test('getActPath returns absolute path or null based on execution check', function () {
    Process::fake(['which act' => '/usr/local/bin/act']);
    expect(pipelineHelper()->getActPath())->toBe('/usr/local/bin/act');

    Process::fake(['which act' => Process::result(output: '', exitCode: 1)]);
    expect(pipelineHelper()->getActPath())->toBeNull();
});
