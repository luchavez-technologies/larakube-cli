<?php

use Illuminate\Support\Facades\Process;

test('setup rejects unknown tools with code 1 and error message', function (): void {
    $this->artisan('setup', ['--tools' => 'nonexistent_tool'])
        ->expectsOutputToContain("Unknown tool 'nonexistent_tool'")
        ->assertExitCode(1);
});

test('setup --tools installs targeted tools and early-exits without full setup', function (): void {
    Process::fake([
        'command -v k9s' => Process::result('/usr/local/bin/k9s'),
        'command -v gh' => Process::result('/usr/local/bin/gh'),
    ]);

    $this->artisan('setup', ['--tools' => 'k9s,gh'])
        ->expectsOutputToContain('Configuring k9s (Kubernetes Terminal UI)...')
        ->expectsOutputToContain('Already installed at: /usr/local/bin/k9s')
        ->expectsOutputToContain('Configuring GitHub CLI (gh)...')
        ->expectsOutputToContain('Already installed at: /usr/local/bin/gh')
        ->doesntExpectOutputToContain('Traefik Ingress Controller')
        ->assertExitCode(0);
});

test('setup --tool accepts array of individual tools', function (): void {
    Process::fake([
        'command -v tofu' => Process::result('/usr/local/bin/tofu'),
    ]);

    $this->artisan('setup', ['--tool' => ['tofu']])
        ->expectsOutputToContain('Configuring OpenTofu (Infrastructure Provisioner)...')
        ->expectsOutputToContain('Already installed at: /usr/local/bin/tofu')
        ->assertExitCode(0);
});

test('setup --tools=gcloud configures gcloud and checks auth', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result('/usr/bin/gcloud'),
        '*gcloud auth print-access-token*' => Process::result('ya29.fake-token'),
    ]);

    $this->artisan('setup', ['--tools' => 'gcloud'])
        ->expectsOutputToContain('Configuring Google Cloud SDK (gcloud CLI)...')
        ->expectsOutputToContain('Already installed at: /usr/bin/gcloud')
        ->assertExitCode(0);
});

test('setup --tools=tea configures tea', function (): void {
    Process::fake([
        'command -v tea' => Process::result('/usr/local/bin/tea'),
    ]);

    $this->artisan('setup', ['--tools' => 'tea'])
        ->expectsOutputToContain('Configuring Tea CLI (Forgejo / Gitea)...')
        ->expectsOutputToContain('Already installed at: /usr/local/bin/tea')
        ->assertExitCode(0);
});
