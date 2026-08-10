<?php

use App\Enums\ClusterTool;
use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(InteractsWithToolRegistry::class);

test('ClusterTool deploymentName, commonsDatabases, and dbSecretRef support named instances', function () {
    expect(ClusterTool::NOTES->deploymentName('sister'))->toBe('notes-outline-sister')
        ->and(ClusterTool::NOTES->commonsDatabases('sister'))->toBe(['outline_sister'])
        ->and(ClusterTool::DATA->dbSecretRef('sister'))->toBe([
            'namespace' => 'larakube-shared',
            'secret' => 'data-secrets-sister',
            'key' => 'db-password',
        ]);
});

test('notes:init deploys a named multi-instance with isolated DB and secrets', function () {
    // A bare, dot-less --domain so ClusterTool::instanceSlugFromHost() derives
    // exactly 'sister' (no dots to dash-ify) — keeps every fixture below
    // (notes-secrets-sister, notes-outline-sister, ...) matching the slug the
    // command actually computes now that the instance IS the host, not a
    // separately-typed --instance flag.
    $registryJson = json_encode([
        ['tool' => 'sso', 'instance' => 'main', 'host' => 'sso.kube', 'installedAt' => '2026-08-01T00:00:00+00:00'],
    ]);

    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => [['id' => 'proj-1', 'name' => 'LaraKube Shared Tools']]], 200),
        '*/management/v1/projects/proj-1/apps/_search' => Http::response(['result' => []], 200),
        '*/management/v1/projects/proj-1/apps/oidc' => Http::response(['appId' => 'app-1', 'clientId' => 'client-1', 'clientSecret' => 'secret-1'], 200),
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

    $this->artisan('notes:init', ['environment' => 'local', '--domain' => 'sister', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Outline wiki stack is live');
});
