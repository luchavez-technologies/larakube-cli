<?php

use App\Http\Integrations\Zitadel\Requests\SearchUsersRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('stalwart:show is registered', function (): void {
    ssoRegistered();
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('stalwart:show');
});

test('stalwart:show requires installed stalwart', function (): void {
    ssoRegistered();
    Process::fake(['*larakube.io/tool=mail*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('stalwart:show')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('stalwart:show displays admin credentials', function (): void {
    ssoRegistered();
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('s3cret-p@ss')),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $this->artisan('stalwart:show')
        ->assertExitCode(0)
        ->expectsOutputToContain('admin')
        ->expectsOutputToContain('s3cret-p@ss');
});

test('stalwart:show <email> displays that account\'s client setup, never a password', function (): void {
    ssoRegistered();
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment zitadel-sso-example-com*' => Process::result(output: ''),
        '*-l larakube.io/tool=webmail --no-headers*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice Smith', 'emailAddress' => 'alice@example.com', 'roles' => ['@type' => 'User']]], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('stalwart:show', ['--email' => 'alice@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('alice@example.com')
        ->expectsOutputToContain('Alice Smith')
        ->expectsOutputToContain('Issue a new one')
        ->doesntExpectOutputToContain('test-admin-pass');
});

test('stalwart:show <email> errors when the account does not exist', function (): void {
    ssoRegistered();
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => []], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('stalwart:show', ['--email' => 'ghost@example.com'])
        ->assertExitCode(1)
        ->expectsOutputToContain("Account 'ghost@example.com' not found");
});

test('stalwart:show <email> shows the webmail URL when Bulwark is installed', function (): void {
    ssoRegistered();
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment zitadel-sso-example-com*' => Process::result(output: ''),
        '*-l larakube.io/tool=webmail --no-headers*' => Process::result(output: 'bulwark-webmail-example-com   1/1   1   1   10d'),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice Smith', 'emailAddress' => 'alice@example.com', 'roles' => ['@type' => 'User']]], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('stalwart:show', ['--email' => 'alice@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Webmail:');
});

test('stalwart:show <email> shows SSO status when Zitadel is installed', function (): void {
    ssoRegistered();
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment zitadel-sso-example-com*' => Process::result(output: 'zitadel-sso-example-com   1/1   1   1   10d'),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice Smith', 'emailAddress' => 'alice@example.com', 'roles' => ['@type' => 'User']]], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'zid-1']]]),
    ]);

    $this->artisan('stalwart:show', ['--email' => 'alice@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('SSO:');
});

test('stalwart:show emits json when --json is passed', function (): void {
    ssoRegistered();
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('s3cret-p@ss')),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $exitCode = Illuminate\Support\Facades\Artisan::call('stalwart:show', ['--json' => true]);
    $output = Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"installed": true')
        ->toContain('"adminPassword": "s3cret-p@ss"');
});

test('stalwart:show with email emits json when --json is passed', function (): void {
    ssoRegistered();
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment zitadel-sso-example-com*' => Process::result(output: ''),
        '*-l larakube.io/tool=webmail --no-headers*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice Smith', 'emailAddress' => 'alice@example.com', 'roles' => ['@type' => 'User']]], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    $exitCode = Illuminate\Support\Facades\Artisan::call('stalwart:show', ['--email' => 'alice@example.com', '--json' => true]);
    $output = Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"email": "alice@example.com"')
        ->toContain('"name": "Alice Smith"');
});

test('stalwart:show resolves Bulwark webmail host from registry and emits webmailUrl in json', function (): void {
    ssoRegistered();
    Tests\Support\FakeToolRegistry::install([
        ['tool' => 'bulwark', 'instance' => 'mail-luchtech-dev', 'host' => 'mail.luchtech.dev'],
    ]);
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('s3cret-p@ss')),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $exitCode = Illuminate\Support\Facades\Artisan::call('stalwart:show', ['--json' => true]);
    $output = Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"webmailUrl": "https://mail.luchtech.dev"');
});

test('stalwart:show resolves active outbound relay and emits relay in json', function (): void {
    ssoRegistered();
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('s3cret-p@ss')),
        '*get secret stalwart-relay*' => Process::result(output: base64_encode('ses')),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        App\Http\Integrations\Stalwart\Requests\JmapRequest::class => MockResponse::make([
            'methodResponses' => [
                ['x:MtaRoute/query', ['ids' => ['r1']], 'c0'],
                ['x:MtaRoute/get', ['list' => [['id' => 'r1', 'name' => 'ses', 'address' => 'email-smtp.us-east-1.amazonaws.com', 'port' => 2587]]], 'c1'],
            ],
            'sessionState' => 'x',
        ]),
    ]);

    $exitCode = Illuminate\Support\Facades\Artisan::call('stalwart:show', ['--json' => true]);
    $output = Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"relay"')
        ->toContain('"provider": "ses"');
});

test('stalwart:show with production environment and explicit context emits json successfully', function (): void {
    ssoRegistered();
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('s3cret-p@ss')),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $exitCode = Illuminate\Support\Facades\Artisan::call('stalwart:show', [
        'environment' => 'production',
        '--context' => 'remote-cluster-ctx',
        '--json' => true,
    ]);
    $output = Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"installed": true');
});
