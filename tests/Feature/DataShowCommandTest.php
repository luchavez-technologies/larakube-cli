<?php

use Illuminate\Support\Facades\Process;

/**
 * The registry is always empty here, so "installed" can only come from matching
 * an engine- and instance-suffixed Deployment by name.
 */
function dataShowFakes(array $deployments): void
{
    Process::fake([
        // Empty registry: the fallback is the only path to "installed".
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        '*get deployment data-directus*' => Process::result(output: ''),
        '*get deployment -n larakube-shared *' => Process::result(output: implode("\n", $deployments)),
        '*data-secrets-data-test*admin-email*' => Process::result(output: base64_encode('admin@example.com')),
        '*data-secrets-data-test*admin-password*' => Process::result(output: base64_encode('s3cret-pass')),
        '*' => Process::result(output: ''),
    ]);
}

test('data:show finds an unregistered PocketBase instance by its Deployment name', function (): void {
    dataShowFakes(['data-pocketbase-data-test']);

    $this->artisan('data:show local --domain=data.test --context=orbstack')
        ->assertExitCode(0)
        ->expectsOutputToContain('s3cret-pass');
});

test('data:show still reports not installed when no Data Deployment exists', function (): void {
    dataShowFakes(['kube-state-metrics', 'notes-outline-notes-test']);

    $this->artisan('data:show local --domain=data.test --context=orbstack')
        ->assertExitCode(1)
        ->expectsOutputToContain('not installed');
});

test('data:show does not mistake a different instance for the one asked about', function (): void {
    dataShowFakes(['data-pocketbase-data-other']);

    $this->artisan('data:show local --domain=data.test --context=orbstack')
        ->assertExitCode(1)
        ->expectsOutputToContain('not installed');
});
