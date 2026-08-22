<?php

use App\Http\Integrations\Zitadel\Requests\DeleteUserGrantRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUserGrantsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUsersRequest;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

Prompt::interactive(false);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('secrets:revoke is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('secrets:revoke');
});

test('secrets:revoke removes exactly the named role when --role is given', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => Process::result(),
    ]);

    $grantSearchCallCount = 0;
    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'rbac-proj-1']]]),
        SearchUserGrantsRequest::class => function () use (&$grantSearchCallCount) {
            $grantSearchCallCount++;

            return $grantSearchCallCount === 1
                ? MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => ['secrets-my-app-local-developer']]]])
                : MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => []]]]);
        },
        DeleteUserGrantRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('secrets:revoke', [
        'environment' => 'local',
        '--app' => 'my-app',
        '--role' => 'developer',
        '--email' => 'dev@example.com',
        '--force' => true,
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Revoked [secrets-my-app-local-developer] from dev@example.com');

    Saloon::assertSent(DeleteUserGrantRequest::class);
});

test('secrets:revoke reports nothing to revoke when the user holds no access for this app/env', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'rbac-proj-1']]]),
        SearchUserGrantsRequest::class => MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => ['secrets-other-app-local-developer']]]]),
    ]);

    $this->artisan('secrets:revoke', [
        'environment' => 'local',
        '--app' => 'my-app',
        '--email' => 'dev@example.com',
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('nothing to revoke');
});

test('secrets:revoke rejects an invalid role', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'rbac-proj-1']]]),
    ]);

    $this->artisan('secrets:revoke', [
        'environment' => 'local',
        '--app' => 'my-app',
        '--role' => 'superadmin',
        '--email' => 'dev@example.com',
        '--no-interaction' => true,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain("isn't a valid role");
});
