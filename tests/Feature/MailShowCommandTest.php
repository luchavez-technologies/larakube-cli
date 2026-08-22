<?php

use App\Http\Integrations\Zitadel\Requests\SearchUsersRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:show is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:show');
});

test('mail:show requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:show')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:show displays admin credentials', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('s3cret-p@ss')),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $this->artisan('mail:show')
        ->assertExitCode(0)
        ->expectsOutputToContain('admin')
        ->expectsOutputToContain('s3cret-p@ss');
});

test('mail:show <email> displays that account\'s client setup, never a password', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*part-of=webmail*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice Smith', 'emailAddress' => 'alice@example.com', 'roles' => ['@type' => 'User']]], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:show', ['--email' => 'alice@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('alice@example.com')
        ->expectsOutputToContain('Alice Smith')
        ->expectsOutputToContain('Issue a new one')
        ->doesntExpectOutputToContain('test-admin-pass');
});

test('mail:show <email> errors when the account does not exist', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => []], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:show', ['--email' => 'ghost@example.com'])
        ->assertExitCode(1)
        ->expectsOutputToContain("Account 'ghost@example.com' not found");
});

test('mail:show <email> shows the webmail URL when Bulwark is installed', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*part-of=webmail*' => Process::result(output: 'webmail-bulwark   1/1   1   1   10d'),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice Smith', 'emailAddress' => 'alice@example.com', 'roles' => ['@type' => 'User']]], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:show', ['--email' => 'alice@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Webmail:');
});

test('mail:show <email> shows SSO status when Zitadel is installed', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'alice', 'description' => 'Alice Smith', 'emailAddress' => 'alice@example.com', 'roles' => ['@type' => 'User']]], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'zid-1']]]),
    ]);

    $this->artisan('mail:show', ['--email' => 'alice@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('SSO:');
});
