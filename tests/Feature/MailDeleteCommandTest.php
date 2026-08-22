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
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:delete')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:delete deletes account by email', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: ''),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["b"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"b","name":"admin","description":"Admin","emailAddress":"admin@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"destroyed":["b"]},"c1"]],"sessionState":"x"}');
        },
    ]);

    $exitCode = Artisan::call('mail:delete', ['--email' => 'admin@example.com', '--force' => true]);

    expect($exitCode)->toBe(0);
});

test('mail:delete --sso removes the matching Zitadel identity', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["b"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"b","name":"admin","description":"Admin","emailAddress":"admin@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"destroyed":["b"]},"c1"]],"sessionState":"x"}');
        },
    ]);

    Saloon::fake([
        DeleteUserRequest::class => MockResponse::make(['details' => []]),
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'zid-1']]]),
    ]);

    $this->artisan('mail:delete', ['--email' => 'admin@example.com', '--force' => true, '--sso' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('SSO identity for admin@example.com removed');
});
