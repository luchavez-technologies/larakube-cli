<?php

use App\Http\Integrations\Zitadel\Requests\CreateProjectGrantRequest;
use App\Http\Integrations\Zitadel\Requests\SearchOrganizationsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectGrantsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectRolesRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectsRequest;
use App\Http\Integrations\Zitadel\Requests\UpdateProjectGrantRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

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
    Saloon::fake([SearchOrganizationsRequest::class => MockResponse::make(['result' => []])]);

    $this->artisan('sso:org-grant', ['--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('No Zitadel organizations exist yet');
});

test('sso:org-grant fails cleanly when the org does not exist', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());
    Saloon::fake([SearchOrganizationsRequest::class => MockResponse::make(['result' => []])]);

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("No Zitadel organization named 'partner.example'");
});

test('sso:org-grant creates a project grant using every role defined on the project by default', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());
    Saloon::fake([
        SearchOrganizationsRequest::class => MockResponse::make(['result' => [['id' => 'org-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => [['key' => 'reader'], ['key' => 'editor']]]),
        SearchProjectGrantsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectGrantRequest::class => MockResponse::make(['grantId' => 'grant-1']),
    ]);

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--project' => 'LaraKube Shared Tools', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("'partner.example' now has scoped access to 'LaraKube Shared Tools'")
        ->expectsOutputToContain('reader, editor');

    Saloon::assertSent(fn ($request) => $request instanceof CreateProjectGrantRequest
        && $request->body()->get('grantedOrgId') === 'org-1'
        && $request->body()->get('roleKeys') === ['reader', 'editor']);
});

test('sso:org-grant merges new roles into an existing grant instead of replacing it', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());
    Saloon::fake([
        SearchOrganizationsRequest::class => MockResponse::make(['result' => [['id' => 'org-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
        SearchProjectGrantsRequest::class => MockResponse::make(['result' => [
            ['grantId' => 'grant-1', 'grantedOrgId' => 'org-1', 'grantedRoleKeys' => ['reader']],
        ]]),
        UpdateProjectGrantRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--project' => 'LaraKube Shared Tools', '--role' => ['editor'], '--no-interaction' => true])
        ->assertExitCode(0);

    Saloon::assertSent(fn ($request) => $request instanceof UpdateProjectGrantRequest
        && $request->body()->get('roleKeys') === ['reader', 'editor']);
});

test('sso:org-grant --tool= resolves the project the same way sso:grant does', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());
    Saloon::fake([
        SearchOrganizationsRequest::class => MockResponse::make(['result' => [['id' => 'org-1']]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-secrets']]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => [['key' => 'openbao-admin']]]),
        SearchProjectGrantsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectGrantRequest::class => MockResponse::make(['grantId' => 'grant-1']),
    ]);

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--tool' => 'secrets', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("'partner.example' now has scoped access to 'openbao-backend'");

    Saloon::assertSent(fn ($request) => $request instanceof SearchProjectsRequest
        && $request->body()->get('queries')[0]['nameQuery']['name'] === 'openbao-backend');
    Saloon::assertSent(fn ($request) => $request instanceof CreateProjectGrantRequest
        && $request->body()->get('grantedOrgId') === 'org-1'
        && $request->body()->get('roleKeys') === ['openbao-admin']);
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
    Saloon::fake([SearchOrganizationsRequest::class => MockResponse::make(['result' => [['id' => 'org-1']]])]);

    $this->artisan('sso:org-grant', ['--org' => 'partner.example', '--tool' => 'notes', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('has multiple instances — pass --domain= to pick one');
});

test('sso:org-grant prompts for an org when --org is omitted and orgs exist', function (): void {
    Process::fake(ssoOrgGrantProcessFakes());
    Saloon::fake([
        SearchOrganizationsRequest::class => MockResponse::make(['result' => [
            ['id' => 'org-1', 'name' => 'partner.example'],
            ['id' => 'org-2', 'name' => 'other.example'],
        ]]),
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1']]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => [['key' => 'reader']]]),
        SearchProjectGrantsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectGrantRequest::class => MockResponse::make(['grantId' => 'grant-1']),
    ]);

    $this->artisan('sso:org-grant', ['--project' => 'LaraKube Shared Tools', '--no-interaction' => true])
        ->expectsChoice('Which org?', 'org-1', [
            'org-1' => 'partner.example',
            'org-2' => 'other.example',
        ])
        ->assertExitCode(0);

    Saloon::assertSent(fn ($request) => $request instanceof CreateProjectGrantRequest
        && $request->body()->get('grantedOrgId') === 'org-1');
});
