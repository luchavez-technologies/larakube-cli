<?php

use App\Facades\State;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::fake([
        // Not what these tests are about — only --email validation is —
        // so the auth-plugin preflight passes through cleanly.
        'command -v gke-gcloud-auth-plugin' => Process::result('/usr/local/bin/gke-gcloud-auth-plugin'),
        '*' => Process::result(output: '', exitCode: 1),
    ]);
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

test('cloud:init:gke skips installation if traefik deployment has ready replicas', function (): void {
    $executed = [];
    Process::fake(function ($process) use (&$executed) {
        $cmd = $process->command;
        $executed[] = $cmd;

        if (str_contains($cmd, 'gke-gcloud-auth-plugin')) {
            return Process::result('/usr/local/bin/gke-gcloud-auth-plugin');
        }
        if (str_contains($cmd, 'get deployment -n traefik traefik')) {
            return Process::result('1');
        }
        if (str_contains($cmd, 'get svc -n traefik traefik')) {
            return Process::result('34.120.10.5');
        }

        return Process::result('');
    });

    $this->artisan('cloud:init:gke', [
        '--context' => 'gke_my-project_us-central1-a_larakube-cluster',
        '--email' => 'admin@example.com',
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

test('cloud:init:gke installs traefik with standard-rwo storage class and removes pending pvc', function (): void {
    $appliedManifest = null;
    Process::fake(function ($process) use (&$appliedManifest) {
        $cmd = $process->command;

        if (str_contains($cmd, 'command -v gke-gcloud-auth-plugin')) {
            return Process::result('/usr/local/bin/gke-gcloud-auth-plugin');
        }

        if (str_contains($cmd, 'apply -f')) {
            preg_match('/apply -f (\'[^\']*\'|"[^"]*"|\S+)/', $cmd, $m);
            $path = trim($m[1] ?? '', '\'"');
            if ($path !== '' && file_exists($path)) {
                $appliedManifest = file_get_contents($path);
            }

            return Process::result(output: 'customresourcedefinition.apiextensions.k8s.io/ingressroutes.traefik.io created', exitCode: 0);
        }

        return match (true) {
            str_contains($cmd, 'get deployment -n traefik traefik') => Process::result(output: '0'),
            str_contains($cmd, 'get pvc -n traefik traefik-acme') => Process::result(output: 'Pending'),
            str_contains($cmd, 'delete pvc -n traefik traefik-acme') => Process::result(output: 'persistentvolumeclaim "traefik-acme" deleted'),
            str_contains($cmd, 'rollout status') => Process::result(output: 'deployment "traefik" successfully rolled out'),
            str_contains($cmd, 'get svc -n traefik traefik') => Process::result(output: '34.120.10.5'),
            default => Process::result(output: '', exitCode: 0),
        };
    });

    $this->artisan('cloud:init:gke', [
        '--context' => 'gke_my-project_us-central1-a_larakube-cluster',
        '--email' => 'admin@example.com',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull()
        ->and($appliedManifest)->toContain('storageClassName: standard-rwo')
        ->and($appliedManifest)->toContain('storage: 1Gi');
});
