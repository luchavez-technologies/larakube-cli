<?php

use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('webmail:init is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('webmail:init');
});

test('webmail:init refuses when Stalwart is not installed', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('webmail:init local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('webmail:init deploys Bulwark and enables CORS when Stalwart is present', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret bulwark-secrets-*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*' => Process::result(),
    ]);

    // CORS is written as a JMAP update on the x:Http singleton.
    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Http/set', ['updated' => ['singleton' => null]], 'c1']]]),
    ]);

    $this->artisan('webmail:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Bulwark manifests...')
        ->expectsOutputToContain('Bulwark webmail is live.')
        ->doesntExpectOutputToContain('Could not auto-enable CORS');
});

test('webmail bulwark manifest references standard secret keys', function (): void {
    $manifest = view('k8s.webmail.bulwark', [
        'volumeSize' => fn (string $claim, string $default) => $default,
        'host' => 'mail.example.com',
        'mailHost' => 'send.example.com',
        'appName' => 'Webmail',
        'vpnOnly' => false,
        'isLocal' => false,
    ])->render();

    expect($manifest)->toContain('key: WEBMAIL_SESSION_SECRET')
        ->toContain('key: WEBMAIL_ADMIN_PASSWORD')
        // Every name is the component's, never the category's (ADR 0021).
        ->toContain('name: bulwark-mail-example-com')
        ->toContain('name: bulwark-secrets-mail-example-com')
        ->toContain('name: bulwark-storage-mail-example-com')
        ->not->toContain('webmail-bulwark');
});

test('webmail:init still succeeds but warns when the CORS flip fails', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret bulwark-secrets-*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*' => Process::result(),
    ]);

    // The JMAP call that writes the CORS setting fails (e.g. endpoint moved).
    Saloon::fake([
        MockResponse::make(['errors' => ['internal error']], 500),
    ]);

    $this->artisan('webmail:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Bulwark webmail is live.')
        ->expectsOutputToContain('Could not auto-enable CORS on Stalwart.');
});

test('webmail:init --vpn-only creates the Traefik Middleware before applying the manifests', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret bulwark-secrets-*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Http/set', ['updated' => ['singleton' => null]], 'c1']]]),
    ]);

    $this->artisan('webmail:init local --vpn-only')
        ->assertExitCode(0)
        ->expectsOutputToContain('Ensuring VPN-only Middleware for Webmail UI (Bulwark)...')
        ->expectsOutputToContain('Bulwark webmail is live.');
});

test('webmail:remove deletes the Bulwark resources', function (): void {
    Process::fake([...registeredToolRemoveFakes('webmail:remove', instance: 'webmail-example-com', host: 'webmail.example.com'),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('webmail:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Bulwark webmail resources...')
        ->expectsOutputToContain('removed from larakube-shared');

    // The component list is what teardown deletes, so it can't drift from the
    // manifests — including the PVC, which is named per instance like the rest.
    Process::assertRan(fn ($job) => str_contains($job->command, 'deployment/bulwark-webmail-example-com')
        && str_contains($job->command, 'ingress/bulwark-webmail-example-com')
        && str_contains($job->command, 'secret/bulwark-secrets-webmail-example-com')
        && str_contains($job->command, 'pvc/bulwark-storage-webmail-example-com'));
});

test('webmail:remove aborts when a delete step fails', function (): void {
    Process::fake([...registeredToolRemoveFakes('webmail:remove'),
        '*delete *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('webmail:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('failed to remove');
});
