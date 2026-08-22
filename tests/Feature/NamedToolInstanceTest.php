<?php

use App\Enums\ClusterTool;
use App\Http\Integrations\Zitadel\Requests\CreateOidcAppRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectAppsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectsRequest;
use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

pest()->use(InteractsWithToolRegistry::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('ClusterTool deploymentName, commonsDatabases, and dbSecretRef support named instances', function (): void {
    expect(ClusterTool::NOTES->deploymentName('sister'))->toBe('notes-outline-sister')
        ->and(ClusterTool::NOTES->commonsDatabases('sister'))->toBe(['outline_sister'])
        ->and(ClusterTool::DATA->dbSecretRef('sister'))->toBe([
            'namespace' => 'larakube-shared',
            'secret' => 'data-secrets-sister',
            'key' => 'db-password',
        ]);
});

test('notes:init deploys a named multi-instance with isolated DB and secrets', function (): void {
    // A bare, dot-less --domain so ClusterTool::instanceSlugFromHost() derives
    // exactly 'sister' (no dots to dash-ify) — keeps every fixture below
    // (notes-secrets-sister, notes-outline-sister, ...) matching the slug the
    // command actually computes now that the instance IS the host, not a
    // separately-typed --instance flag.
    $registryJson = json_encode([
        ['tool' => 'sso', 'instance' => 'main', 'host' => 'sso.kube', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    Saloon::fake([
        SearchProjectsRequest::class => MockResponse::make(['result' => [['id' => 'proj-1', 'name' => 'LaraKube Shared Tools']]], 200),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []], 200),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'client-1', 'clientSecret' => 'secret-1'], 200),
    ]);

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode($registryJson)),
        '*get configmap plex-commons*' => Process::result(output: json_encode([
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ])),
        '*get configmap plex-registry*' => Process::result(output: json_encode(['tenants' => []])),
        '*get secret plex-admin*' => Process::result(output: base64_encode('s3-credential-val')),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('pat-token')),
        '*get secret sso-zitadel-host*' => Process::result(output: base64_encode('sso.kube')),
        '*get secret notes-secrets-sister*' => Process::result(output: '', exitCode: 1),
        '*get secret notes-outline-oidc-sister*' => Process::result(output: '', exitCode: 1),
        '*get deployment notes-outline-sister*' => Process::result(output: 'notes-outline-sister 1/1'),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel 1/1'),
        '*' => Process::result(output: 'success'),
    ]);

    $this->artisan('notes:init', ['environment' => 'local', '--domain' => 'sister', '--admin-email' => 'admin@example.com', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Outline wiki stack is live');
});
