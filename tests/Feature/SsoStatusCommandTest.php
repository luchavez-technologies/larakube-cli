<?php

use App\Enums\ClusterTool;
use App\Http\Integrations\Zitadel\Requests\DeleteProjectRequest;
use App\Http\Integrations\Zitadel\Requests\ListProjectsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchOrganizationsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectUserGrantsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUserGrantsRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\Support\FakeToolRegistry;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('sso:status --json reports not installed when Zitadel has no Deployment', function (): void {
    FakeToolRegistry::install([]);
    Process::fake([
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    Artisan::call('sso:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output)->toBe([
        'success' => true,
        'installed' => false,
    ]);

    Saloon::assertNothingSent();
});

test('sso:status --json correctly reports wired tools and ignores unwired ones', function (): void {
    FakeToolRegistry::install([
        ssoRegistryRow(),
        ['tool' => 'grafana', 'instance' => 'grafana-example-com', 'host' => 'grafana.example.com'],
        ['tool' => 'forgejo', 'instance' => 'git-example-com', 'host' => 'git.example.com'],
    ]);

    Process::fake([
        '*get deployment zitadel-sso-example-com*' => Process::result(output: 'zitadel-sso-example-com   1/1   1   1   10d'),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('zitadel-pat')),
        '*grafana-sso*' => Process::result(output: base64_encode('cid-grafana')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['result' => [
            ['id' => 'proj-grafana', 'name' => 'grafana'],
        ]]),
        SearchProjectUserGrantsRequest::class => MockResponse::make(['result' => []]),
        SearchOrganizationsRequest::class => MockResponse::make(['result' => [
            ['id' => 'org-1', 'name' => 'luchtech.dev'],
        ]]),
    ]);

    Artisan::call('sso:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['success'])->toBeTrue()
        ->and($output['installed'])->toBeTrue()
        ->and($output['org'])->toBe('luchtech.dev')
        ->and($output['wiredTools'])->toHaveCount(1)
        ->and($output['wiredTools'][0]['slug'])->toBe('grafana')
        ->and($output['wiredTools'][0]['tool'])->toBe('Grafana (Metrics & Dashboards)')
        ->and($output['wiredTools'][0]['instance'])->toBe('grafana-example-com')
        ->and($output['wiredTools'][0]['host'])->toBe('grafana.example.com')
        ->and($output['wiredTools'][0]['wired'])->toBeTrue();
});

test('sso:status --json grants excludes secrets-* keys and resolves real emails', function (): void {
    FakeToolRegistry::install([
        ssoRegistryRow(),
        ['tool' => 'grafana', 'instance' => 'grafana-example-com', 'host' => 'grafana.example.com'],
    ]);

    Process::fake([
        '*get deployment zitadel-sso-example-com*' => Process::result(output: 'zitadel-sso-example-com   1/1   1   1   10d'),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('zitadel-pat')),
        '*grafana-sso*' => Process::result(output: base64_encode('cid-grafana')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['result' => [
            ['id' => 'proj-shared', 'name' => ClusterTool::ssoAdminProjectName()],
            ['id' => 'proj-grafana', 'name' => 'grafana'],
        ]]),
        SearchProjectUserGrantsRequest::class => function ($pendingRequest) {
            $projectId = $pendingRequest->getRequest()->body()->get('queries')[0]['projectIdQuery']['projectId'] ?? '';

            if ($projectId === 'proj-grafana') {
                return MockResponse::make(['result' => [
                    [
                        'id' => 'grant-1',
                        'email' => 'alice@luchtech.dev',
                        'roleKeys' => ['grafana-user', 'secrets-grafana-local-developer', 'secrets-other-role'],
                    ],
                ]]);
            }

            if ($projectId === 'proj-shared') {
                return MockResponse::make(['result' => [
                    [
                        'id' => 'grant-2',
                        'email' => 'bob@luchtech.dev',
                        'roleKeys' => ['ocisAdmin'],
                    ],
                ]]);
            }

            return MockResponse::make(['result' => []]);
        },
        SearchOrganizationsRequest::class => MockResponse::make(['result' => [
            ['id' => 'org-1', 'name' => 'luchtech.dev'],
        ]]),
    ]);

    Artisan::call('sso:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['grants'])->toHaveCount(2);

    $roles = array_column($output['grants'], 'role');
    $emails = array_column($output['grants'], 'email');

    expect($roles)->toContain('grafana-user')
        ->and($roles)->toContain('ocisAdmin')
        ->and($roles)->not->toContain('secrets-grafana-local-developer')
        ->and($roles)->not->toContain('secrets-other-role')
        ->and($emails)->toContain('alice@luchtech.dev')
        ->and($emails)->toContain('bob@luchtech.dev');
});

test('sso:status --json orphanedProjects never calls a delete method', function (): void {
    FakeToolRegistry::install([
        ssoRegistryRow(),
        ['tool' => 'grafana', 'instance' => 'grafana-example-com', 'host' => 'grafana.example.com'],
    ]);

    Process::fake([
        '*get deployment zitadel-sso-example-com*' => Process::result(output: 'zitadel-sso-example-com   1/1   1   1   10d'),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('zitadel-pat')),
        '*grafana-sso*' => Process::result(output: base64_encode('cid-grafana')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['result' => [
            ['id' => 'proj-grafana', 'name' => 'grafana'],
            ['id' => 'proj-abandoned', 'name' => 'stranded-old-forgejo'],
        ]]),
        SearchProjectUserGrantsRequest::class => MockResponse::make(['result' => []]),
        SearchOrganizationsRequest::class => MockResponse::make(['result' => [
            ['id' => 'org-1', 'name' => 'luchtech.dev'],
        ]]),
    ]);

    Artisan::call('sso:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['orphanedProjects'])->toBe(['stranded-old-forgejo']);

    Saloon::assertNotSent(DeleteProjectRequest::class);
});

test('sso:status executes exact expected API calls without per-tool or per-user loop', function (): void {
    FakeToolRegistry::install([
        ssoRegistryRow(),
        ['tool' => 'grafana', 'instance' => 'grafana-example-com', 'host' => 'grafana.example.com'],
    ]);

    Process::fake([
        '*get deployment zitadel-sso-example-com*' => Process::result(output: 'zitadel-sso-example-com   1/1   1   1   10d'),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('zitadel-pat')),
        '*grafana-sso*' => Process::result(output: base64_encode('cid-grafana')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['result' => [
            ['id' => 'proj-shared', 'name' => ClusterTool::ssoAdminProjectName()],
            ['id' => 'proj-grafana', 'name' => 'grafana'],
        ]]),
        SearchProjectUserGrantsRequest::class => MockResponse::make(['result' => [
            ['id' => 'g1', 'email' => 'user@example.com', 'roleKeys' => ['grafana-user']],
        ]]),
        SearchOrganizationsRequest::class => MockResponse::make(['result' => [
            ['id' => 'org-1', 'name' => 'luchtech.dev'],
        ]]),
    ]);

    Artisan::call('sso:status', ['--json' => true]);

    // Exactly 4 calls: ListProjectsRequest (1), SearchProjectUserGrantsRequest (2: shared tools + wired grafana), SearchOrganizationsRequest (1)
    // Proves no per-tool Zitadel API call for every shipped case in ClusterTool (which has ~20 cases)
    Saloon::assertSentCount(4);
    Saloon::mockClient()->assertSentCount(1, ListProjectsRequest::class);
    Saloon::mockClient()->assertSentCount(2, SearchProjectUserGrantsRequest::class);
    Saloon::mockClient()->assertSentCount(1, SearchOrganizationsRequest::class);

    // Proves no per-user zitadelFindUserGrant API loop
    Saloon::assertNotSent(SearchUserGrantsRequest::class);
});

test('sso:status non-json renders formatted tables when installed', function (): void {
    FakeToolRegistry::install([
        ssoRegistryRow(),
        ['tool' => 'grafana', 'instance' => 'grafana-example-com', 'host' => 'grafana.example.com'],
    ]);

    Process::fake([
        '*get deployment zitadel-sso-example-com*' => Process::result(output: 'zitadel-sso-example-com   1/1   1   1   10d'),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('zitadel-pat')),
        '*grafana-sso*' => Process::result(output: base64_encode('cid-grafana')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['result' => [
            ['id' => 'proj-grafana', 'name' => 'grafana'],
            ['id' => 'proj-orphan', 'name' => 'orphan-app'],
        ]]),
        SearchProjectUserGrantsRequest::class => MockResponse::make(['result' => [
            ['id' => 'g1', 'email' => 'operator@luchtech.dev', 'roleKeys' => ['grafana-user']],
        ]]),
        SearchOrganizationsRequest::class => MockResponse::make(['result' => [
            ['id' => 'org-1', 'name' => 'luchtech.dev'],
        ]]),
    ]);

    Artisan::call('sso:status');
    $output = Artisan::output();

    expect($output)->toContain('Grafana (Metrics & Dashboards)')
        ->and($output)->toContain('operator@luchtech.dev')
        ->and($output)->toContain('luchtech.dev');
});
