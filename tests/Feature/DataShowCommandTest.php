<?php

use Illuminate\Support\Facades\Artisan;
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
        '*get deployment directus*' => Process::result(output: ''),
        '*get deployment -n larakube-shared *' => Process::result(output: implode("\n", $deployments)),
        '*pocketbase-secrets-data-test*admin-email*' => Process::result(output: base64_encode('admin@example.com')),
        '*pocketbase-secrets-data-test*admin-password*' => Process::result(output: base64_encode('s3cret-pass')),
        '*' => Process::result(output: ''),
    ]);
}

test('pocketbase:show finds an unregistered PocketBase instance by its Deployment name', function (): void {
    dataShowFakes(['pocketbase-data-test']);

    $this->artisan('pocketbase:show local --domain=data.test --context=orbstack')
        ->assertExitCode(0)
        ->expectsOutputToContain('s3cret-pass');
});

test('directus:show still reports not installed when no Data Deployment exists', function (): void {
    dataShowFakes(['kube-state-metrics', 'outline-notes-test']);

    $this->artisan('directus:show local --domain=data.test --context=orbstack')
        ->assertExitCode(1)
        ->expectsOutputToContain('not installed');
});

test('directus:show does not mistake a different instance for the one asked about', function (): void {
    dataShowFakes(['pocketbase-data-other']);

    $this->artisan('directus:show local --domain=data.test --context=orbstack')
        ->assertExitCode(1)
        ->expectsOutputToContain('not installed');
});

test('pocketbase:show --json includes the bootstrap credentials, not just the host', function (): void {
    // A JSON caller (e.g. the Desktop app, showing creds right after an
    // install) must not have to scrape a Run's plain-text output for these —
    // afterTable() only renders them to the human table, which --json skips.
    dataShowFakes(['pocketbase-data-test']);

    $exit = Artisan::call('pocketbase:show local --domain=data.test --context=orbstack --json');
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exit)->toBe(0)
        ->and($payload['credentials'])->toBe([
            'admin_email' => 'admin@example.com',
            'admin_password' => 's3cret-pass',
        ]);
});

test('n8n:show --json reports no credentials, since there is no seeded admin', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        '*get deployment -n larakube-shared *' => Process::result(output: 'n8n-flow-test'),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('n8n:show local --domain=flow.test --context=orbstack --json');
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exit)->toBe(0)
        ->and($payload['credentials'])->toBeNull();
});
