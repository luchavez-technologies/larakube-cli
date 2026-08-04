<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

Prompt::interactive(false);

/** The Process fakes secrets:grant needs beyond sso:grant's own connection resolution. */
function fakeOpenBaoWiring(): array
{
    return [
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.root-token')),
        '*port-forward*' => Process::result(output: ''),
    ];
}

test('secrets:grant is registered', function () {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('secrets:grant');
});

test('secrets:grant wires an app-scoped OpenBao policy/role and grants the Zitadel role', function () {
    Process::fake(array_merge(fakeOpenBaoWiring(), [
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*' => Process::result(),
    ]));

    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'rbac-proj-1']]]),
        '*/management/v1/projects/rbac-proj-1/roles/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/rbac-proj-1/roles' => Http::response([]),
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/users/grants/_search' => Http::sequence()
            ->push(['result' => []])
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['secrets-my-app-local-developer']]]]),
        '*/management/v1/users/uid-1/grants' => Http::response([]),
        'localhost:*/v1/sys/policies/acl/*' => Http::response([]),
        'localhost:*/v1/auth/oidc/role/*' => Http::response([]),
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

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/sys/policies/acl/secrets-my-app-local-developer-policy')
        && $request->method() === 'PUT'
        && str_contains($request['policy'], 'secret/data/local/my-app/*'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/auth/oidc/role/secrets-my-app-local-developer')
        && $request->method() === 'PUT'
        && $request['bound_claims']['larakube_roles'] === 'secrets-my-app-local-developer');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/uid-1/grants')
        && ! str_contains($request->url(), '_search')
        && $request['roleKeys'] === ['secrets-my-app-local-developer']);
});

test('secrets:grant rejects an invalid role', function () {
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

test('secrets:grant errors when Zitadel is not installed', function () {
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
