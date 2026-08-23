<?php

use App\Http\Integrations\Zitadel\Requests\DeleteUserRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUsersRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:delete is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:delete');
});

test('mail:delete requires installed stalwart', function (): void {
    Process::fake(['*app=mail-stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:delete')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:delete deletes account by email', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['b']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'b', 'name' => 'admin', 'description' => 'Admin', 'emailAddress' => 'admin@example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['destroyed' => ['b']], 'c1']], 'sessionState' => 'x']),
    ]);

    $exitCode = Artisan::call('mail:delete', ['--email' => 'admin@example.com', '--force' => true]);

    expect($exitCode)->toBe(0);
});

test('mail:delete --sso removes the matching Zitadel identity', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['b']], 'c0'], ['x:Account/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'b', 'name' => 'admin', 'description' => 'Admin', 'emailAddress' => 'admin@example.com']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:Account/set', ['destroyed' => ['b']], 'c1']], 'sessionState' => 'x']),
        DeleteUserRequest::class => MockResponse::make(['details' => []]),
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'zid-1']]]),
    ]);

    $this->artisan('mail:delete', ['--email' => 'admin@example.com', '--force' => true, '--sso' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('SSO identity for admin@example.com removed');
});
