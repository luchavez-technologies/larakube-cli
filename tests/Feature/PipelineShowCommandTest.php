<?php

test('pipeline:show fails when no workflows exist', function () {
    $tempDir = sys_get_temp_dir().'/larakube-pipe-show-'.uniqid();
    mkdir($tempDir, 0755, true);
    $tempDir = realpath($tempDir) ?: $tempDir;

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('pipeline:show production')
            ->assertExitCode(1)
            ->expectsOutputToContain('No LaraKube workflows/pipelines found in this project.');
    } finally {
        chdir($originalDir);
        exec('rm -rf '.escapeshellarg($tempDir));
    }
});

test('pipeline:show shows parsed github actions workflow details', function () {
    $tempDir = sys_get_temp_dir().'/larakube-pipe-show-'.uniqid();
    mkdir($tempDir, 0755, true);
    $tempDir = realpath($tempDir) ?: $tempDir;

    mkdir($tempDir.'/.github/workflows', 0755, true);

    $yamlContent = <<<'YAML'
name: Deploy
on:
  push:
    branches: [ main ]
jobs:
  build:
    name: 🔨 Build
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v6
      - name: Build image
        run: docker build .
      - name: Secret ref
        run: echo ${{ secrets.PRODUCTION_KUBECONFIG }}
YAML;

    file_put_contents($tempDir.'/.github/workflows/larakube-deploy-production.yml', $yamlContent);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('pipeline:show production')
            ->assertExitCode(0)
            ->expectsOutputToContain('Pipeline Layout: GitHub Actions')
            ->expectsOutputToContain('Trigger Event:        push (main)')
            ->expectsOutputToContain('Required Secrets:     PRODUCTION_KUBECONFIG')
            ->expectsOutputToContain('● 🔨 Build')
            ->expectsOutputToContain('Step 1: Checkout')
            ->expectsOutputToContain('Step 2: Build image')
            ->expectsOutputToContain('Step 3: Secret ref');
    } finally {
        chdir($originalDir);
        exec('rm -rf '.escapeshellarg($tempDir));
    }
});

test('pipeline:show shows parsed gitlab pipeline details', function () {
    $tempDir = sys_get_temp_dir().'/larakube-pipe-show-'.uniqid();
    mkdir($tempDir, 0755, true);
    $tempDir = realpath($tempDir) ?: $tempDir;

    $yamlContent = <<<'YAML'
stages:
  - deploy
deploy_job:
  stage: deploy
  script:
    - echo $GL_KUBECONFIG
    - kubectl apply -f .
YAML;

    file_put_contents($tempDir.'/.gitlab-ci.yml', $yamlContent);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('pipeline:show all')
            ->assertExitCode(0)
            ->expectsOutputToContain('Pipeline Layout: GitLab CI/CD')
            ->expectsOutputToContain('Required Secrets:     GL_KUBECONFIG')
            ->expectsOutputToContain('● deploy_job')
            ->expectsOutputToContain('Step 1: echo $GL_KUBECONFIG')
            ->expectsOutputToContain('Step 2: kubectl apply -f .');
    } finally {
        chdir($originalDir);
        exec('rm -rf '.escapeshellarg($tempDir));
    }
});
