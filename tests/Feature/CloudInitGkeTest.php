<?php

use App\Facades\State;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);
});

test('cloud:init:gke rejects invalid --email before anything is installed', function (): void {
    $this->artisan('cloud:init:gke', ['--context' => 'gke_my-project_us-central1-a_larakube-cluster', '--email' => 'not-an-email'])
        ->assertExitCode(1);

    expect(State::lastError())->toContain('Invalid --email');
});

test('cloud:init:gke headless with no stored email fails clearly, pointing at --email=', function (): void {
    $this->artisan('cloud:init:gke', ['--context' => 'gke_my-project_us-central1-a_larakube-cluster', '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::lastError())->toContain('--email=');
});

test('cloud:init:managed delegates to cloud:init:gke when provider is gcp', function (): void {
    $this->artisan('cloud:init:managed', [
        '--context' => 'gke_my-project_us-central1-a_larakube-cluster',
        '--provider' => 'gcp',
        '--email' => 'not-an-email',
    ])->assertExitCode(1);

    expect(State::lastError())->toContain('Invalid --email');
});

test('cloud:init:managed delegates to cloud:init:doks when provider is do', function (): void {
    $this->artisan('cloud:init:managed', [
        '--context' => 'do-nyc1-test',
        '--provider' => 'do',
        '--email' => 'not-an-email',
    ])->assertExitCode(1);

    expect(State::lastError())->toContain('Invalid --email');
});
