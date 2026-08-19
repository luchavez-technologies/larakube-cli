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

test('sso:org-grant requires --org', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());

    $this->artisan('sso:org-grant', ['--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('--org is required');
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

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--no-interaction' => true])
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

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--role' => ['editor'], '--no-interaction' => true])
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/projects/proj-1/grants/grant-1')
        && $request->method() === 'PUT'
        && $request['roleKeys'] === ['reader', 'editor']);
});
