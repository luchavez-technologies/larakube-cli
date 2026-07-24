<?php

use Illuminate\Support\Facades\Process;

test('webmail:init is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('webmail:init');
});

test('webmail:init refuses when Stalwart is not installed', function () {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('webmail:init local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('webmail:init deploys Bulwark and enables CORS when Stalwart is present', function () {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret webmail-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        // CORS is now written as a JMAP update on the x:Http singleton, so the
        // in-pod curl has to return a real JMAP envelope — an opaque "ok" is
        // exactly what the dead REST endpoint used to accept.
        '*exec *' => Process::result(output: json_encode([
            'methodResponses' => [['x:Http/set', ['updated' => ['singleton' => null]], 'c1']],
        ])),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('webmail:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Bulwark manifests...')
        ->expectsOutputToContain('Bulwark webmail is live.')
        ->doesntExpectOutputToContain('Could not auto-enable CORS');
});

test('webmail bulwark manifest references standard Infisical secret keys', function () {
    $manifest = view('k8s.webmail.bulwark', [
        'host' => 'mail.example.com',
        'mailHost' => 'send.example.com',
        'appName' => 'Webmail',
        'vpnOnly' => false,
        'isLocal' => false,
    ])->render();

    expect($manifest)->toContain('key: WEBMAIL_SESSION_SECRET');
    expect($manifest)->toContain('key: WEBMAIL_ADMIN_PASSWORD');
});

test('webmail:init still succeeds but warns when the CORS flip fails', function () {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret webmail-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        // The in-pod curl that writes the CORS setting fails (e.g. endpoint moved).
        '*exec *' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('webmail:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Bulwark webmail is live.')
        ->expectsOutputToContain('Could not auto-enable CORS on Stalwart.');
});

test('webmail:init --vpn-only creates the Traefik Middleware before applying the manifests', function () {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret webmail-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*exec *' => Process::result(output: 'ok'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('webmail:init local --vpn-only')
        ->assertExitCode(0)
        ->expectsOutputToContain('Ensuring VPN-only Middleware for Webmail UI (Bulwark)...')
        ->expectsOutputToContain('Bulwark webmail is live.');
});

test('webmail:remove deletes the Bulwark resources', function () {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('webmail:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Bulwark webmail resources...')
        ->expectsOutputToContain('removed from larakube-shared');
});

test('webmail:remove aborts when a delete step fails', function () {
    Process::fake([
        '*delete *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('webmail:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('failed to remove');
});
