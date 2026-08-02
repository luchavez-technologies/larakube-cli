<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('sso:grant is registered', function () {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:grant');
});

test('sso:grant rejects a tool with no role-gated access', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'passwords', '--role' => 'whatever', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('has no role-gated access to grant');
});

test('sso:grant rejects an unknown tool', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'not-a-real-tool', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown tool 'not-a-real-tool'");
});

test('sso:grant\'s picker offers every role-bearing tool — Drive included — without a live-role probe', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-drive*' => Process::result(output: base64_encode('shared-proj-1')),
    ]);

    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/users/grants/_search' => Http::sequence()
            ->push(['result' => []])
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['ocisAdmin']]]]),
        '*/management/v1/users/uid-1/grants' => Http::response([]),
    ]);

    // No --tool: the picker resolves Drive (the first role-bearing tool in
    // case order) purely from the enum's role schema — the grant succeeds even
    // though Zitadel here knows nothing about any roles yet.
    $this->artisan('sso:grant', ['--role' => 'ocisAdmin', '--email' => 'admin@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Granted 'ocisAdmin' to admin@luchtech.dev");

    // The grant targets the drive app's own project — and the picker never
    // consulted the live RBAC/shared project role lists at all.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/uid-1/grants')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST'
        && $request['projectId'] === 'shared-proj-1'
        && $request['roleKeys'] === ['ocisAdmin']);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/roles/_search')
        || str_contains($request->url(), '/management/v1/projects/_search'));
});

test('sso:grant rejects a role the tool does not define', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'secrets', '--role' => 'openbao-superuser', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("isn't a role");
});

test('sso:grant errors when Zitadel is not installed', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: ''),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'secrets', '--role' => 'openbao-admin', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Zitadel is not installed');
});

test('sso:grant errors when no Zitadel user matches the email', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/v2/users' => Http::response(['result' => []]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'secrets', '--role' => 'openbao-admin', '--email' => 'ghost@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('No Zitadel user found');
});

test('sso:grant creates a fresh UserGrant when the user holds none on the RBAC project yet', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/management/v1/users/grants/_search' => Http::sequence()
            ->push(['result' => []]) // no existing grant → create path
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['openbao-admin']]]]), // post-write readback
        '*/management/v1/users/uid-1/grants' => Http::response([]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'secrets', '--role' => 'openbao-admin', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Granted 'openbao-admin' to james@luchtech.dev")
        ->expectsOutputToContain('openbao-admin');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/uid-1/grants')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST'
        && $request['roleKeys'] === ['openbao-admin']);
});

test('sso:grant merges a new role into an existing UserGrant instead of clobbering it', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ]);

    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/management/v1/users/grants/_search' => Http::sequence()
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['openbao-admin']]]])
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['openbao-admin', 'grafana-user']]]]),
        '*/management/v1/users/uid-1/grants/grant-1' => Http::response([]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'monitor', '--role' => 'grafana-user', '--email' => 'james@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Granted 'grafana-user' to james@luchtech.dev");

    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/uid-1/grants/grant-1')
        && $request->method() === 'PUT'
        && $request['roleKeys'] === ['openbao-admin', 'grafana-user']);
});

test('sso:grant grants Drive\'s ocisAdmin on the shared project its app is registered under', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        // sso:wire persisted which project the drive app lives on.
        '*get secret sso-app-drive*' => Process::result(output: base64_encode('shared-proj-1')),
    ]);

    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/users/grants/_search' => Http::sequence()
            ->push(['result' => []])
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['ocisAdmin']]]]),
        '*/management/v1/users/uid-1/grants' => Http::response([]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'drive', '--role' => 'ocisAdmin', '--email' => 'admin@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Granted 'ocisAdmin' to admin@luchtech.dev")
        ->expectsOutputToContain('LaraKube Shared Tools');

    // The grant targets the drive app's own project — and never touches the
    // RBAC project's ensure/search.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/uid-1/grants')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST'
        && $request['projectId'] === 'shared-proj-1'
        && $request['roleKeys'] === ['ocisAdmin']);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/management/v1/projects/_search'));
});

test('sso:grant for Drive falls back to ensuring the shared project when the app secret has no project-id yet', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-drive*' => Process::result(output: ''),
    ]);

    Http::fake([
        '*projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects' => Http::response(['id' => 'shared-proj-1']),
        '*/v2/users' => Http::response(['result' => [['userId' => 'uid-1']]]),
        '*/management/v1/users/grants/_search' => Http::sequence()
            ->push(['result' => []])
            ->push(['result' => [['id' => 'grant-1', 'roleKeys' => ['ocisAdmin']]]]),
        '*/management/v1/users/uid-1/grants' => Http::response([]),
    ]);

    $this->artisan('sso:grant', ['--tool' => 'drive', '--role' => 'ocisAdmin', '--email' => 'admin@luchtech.dev', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Granted 'ocisAdmin' to admin@luchtech.dev");

    // The fallback ensured the shared project by name before granting.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/management/v1/projects')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST'
        && $request['name'] === 'LaraKube Shared Tools');
});
