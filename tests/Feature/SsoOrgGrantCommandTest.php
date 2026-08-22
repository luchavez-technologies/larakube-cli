<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function ssoOrgGrantProcessFakes(): array
{
    return [
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ];
}

test('sso:org-grant is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:org-grant');
});

test('sso:org-grant errors cleanly when --org is omitted and no orgs exist to pick from', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());
    Http::fake([
        '*/v2/organizations/_search' => Http::response(['result' => []]),
    ]);

    $this->artisan('sso:org-grant', ['--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('No Zitadel organizations exist yet');
});

test('sso:org-grant fails cleanly when the org does not exist', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());
    Http::fake([
        '*/v2/organizations/_search' => Http::response(['result' => []]),
    ]);

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("No Zitadel organization named 'partner.example'");
});

test('sso:org-grant creates a project grant using every role defined on the project by default', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());
    Http::fake([
        '*/v2/organizations/_search' => Http::response(['result' => [['id' => 'org-1']]]),
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => [['key' => 'reader'], ['key' => 'editor']]]),
        '*/management/v1/projects/proj-1/grants/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/grants' => Http::response(['grantId' => 'grant-1']),
    ]);

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--project' => 'LaraKube Shared Tools', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("'partner.example' now has scoped access to 'LaraKube Shared Tools'")
        ->expectsOutputToContain('reader, editor');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/projects/proj-1/grants')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST'
        && $request['grantedOrgId'] === 'org-1'
        && $request['roleKeys'] === ['reader', 'editor']);
});

test('sso:org-grant merges new roles into an existing grant instead of replacing it', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());
    Http::fake([
        '*/v2/organizations/_search' => Http::response(['result' => [['id' => 'org-1']]]),
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/management/v1/projects/proj-1/grants/_search' => Http::response(['result' => [
            ['grantId' => 'grant-1', 'grantedOrgId' => 'org-1', 'grantedRoleKeys' => ['reader']],
        ]]),
        '*/management/v1/projects/proj-1/grants/grant-1' => Http::response([]),
    ]);

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--project' => 'LaraKube Shared Tools', '--role' => ['editor'], '--no-interaction' => true])
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/projects/proj-1/grants/grant-1')
        && $request->method() === 'PUT'
        && $request['roleKeys'] === ['reader', 'editor']);
});

test('sso:org-grant --tool= resolves the project the same way sso:grant does', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());
    Http::fake([
        '*/v2/organizations/_search' => Http::response(['result' => [['id' => 'org-1']]]),
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-secrets']]]),
        '*/management/v1/projects/proj-secrets/roles/_search' => Http::response(['result' => [['key' => 'openbao-admin']]]),
        '*/management/v1/projects/proj-secrets/grants/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-secrets/grants' => Http::response(['grantId' => 'grant-1']),
    ]);

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--tool' => 'secrets', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("'partner.example' now has scoped access to 'openbao-backend'");

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/management/v1/projects/_search')
        && $request['queries'][0]['nameQuery']['name'] === 'openbao-backend');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/projects/proj-secrets/grants')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST'
        && $request['grantedOrgId'] === 'org-1'
        && $request['roleKeys'] === ['openbao-admin']);
});

test('sso:org-grant --tool= on a multi-instance tool without --domain= refuses to guess', function (): void {
    Process::fake(array_merge(ssoOrgGrantProcessFakes(), [
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
                ['tool' => 'notes', 'instance' => 'blog-example-com', 'installedAt' => '2026-08-02T00:00:00+00:00', 'host' => 'blog.example.com'],
            ])),
        ),
    ]));
    Http::fake([
        '*/v2/organizations/_search' => Http::response(['result' => [['id' => 'org-1']]]),
    ]);

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--tool' => 'notes', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('has multiple instances — pass --domain= to pick one');
});

test('sso:org-grant prompts for an org when --org is omitted and orgs exist', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());
    Http::fake([
        '*/v2/organizations/_search' => Http::response(['result' => [
            ['id' => 'org-1', 'name' => 'partner.example'],
            ['id' => 'org-2', 'name' => 'other.example'],
        ]]),
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1']]]),
        '*/management/v1/projects/proj-1/roles/_search' => Http::response(['result' => [['key' => 'reader']]]),
        '*/management/v1/projects/proj-1/grants/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/proj-1/grants' => Http::response(['grantId' => 'grant-1']),
    ]);

    $this->artisan('sso:org-grant', ['--project' => 'LaraKube Shared Tools', '--no-interaction' => true])
        ->expectsChoice('Which org?', 'org-1', [
            'org-1' => 'partner.example',
            'org-2' => 'other.example',
        ])
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/projects/proj-1/grants')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST'
        && $request['grantedOrgId'] === 'org-1');
});
