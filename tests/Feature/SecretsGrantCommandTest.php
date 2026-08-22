<?php

use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use App\Http\Integrations\Zitadel\Requests\CreateProjectRoleRequest;
use App\Http\Integrations\Zitadel\Requests\CreateUserGrantRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectRolesRequest;
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

/** The Process fakes secrets:grant needs beyond sso:grant's own connection resolution. */
function fakeOpenBaoWiring(): array
{
    return [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.root-token')),
        '*port-forward*' => Process::result(output: ''),
    ];
}

test('secrets:grant is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('secrets:grant');
});

test('secrets:grant wires an app-scoped OpenBao policy/role and grants the Zitadel role', function (): void {
    Process::fake(array_merge(fakeOpenBaoWiring(), [
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => Process::result(),
    ]));

    $grantSearchCallCount = 0;
    Saloon::fake([
        DynamicRequest::class => MockResponse::make([]),
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'uid-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'rbac-proj-1']]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
        SearchUserGrantsRequest::class => function () use (&$grantSearchCallCount) {
            $grantSearchCallCount++;

            return $grantSearchCallCount === 1
                ? MockResponse::make(['result' => []])
                : MockResponse::make(['result' => [['id' => 'grant-1', 'roleKeys' => ['secrets-my-app-local-developer']]]]);
        },
        CreateUserGrantRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('secrets:grant', [
        'environment' => 'local',
        '--app' => 'my-app',
        '--role' => 'developer',
        '--email' => 'dev@example.com',
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain("Granted dev@example.com 'developer' access to 'my-app' secrets in 'local'")
        ->expectsOutputToContain('secret/data/local/my-app/*');

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '/v1/sys/policies/acl/secrets-my-app-local-developer-policy')
        && $request->getMethod()->value === 'PUT'
        && str_contains($request->body()->get('policy'), 'secret/data/local/my-app/*'));

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '/v1/auth/oidc/role/secrets-my-app-local-developer')
        && $request->getMethod()->value === 'PUT'
        && $request->body()->get('bound_claims')['larakube_roles'] === 'secrets-my-app-local-developer');

    Saloon::assertSent(fn ($request) => $request instanceof CreateUserGrantRequest
        && $request->body()->get('roleKeys') === ['secrets-my-app-local-developer']);
});

test('secrets:grant rejects an invalid role', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => Process::result(),
    ]);

    $this->artisan('secrets:grant', [
        'environment' => 'local',
        '--app' => 'my-app',
        '--role' => 'superadmin',
        '--email' => 'dev@example.com',
        '--no-interaction' => true,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain("isn't a valid role");
});

test('secrets:grant errors when Zitadel is not installed', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: ''),
    ]);

    $this->artisan('secrets:grant', [
        'environment' => 'local',
        '--app' => 'my-app',
        '--role' => 'developer',
        '--email' => 'dev@example.com',
        '--no-interaction' => true,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain('Zitadel is not installed');
});
