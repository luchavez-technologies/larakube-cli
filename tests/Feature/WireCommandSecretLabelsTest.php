<?php

use App\Enums\ClusterTool;
use App\Http\Integrations\Zitadel\Requests\CreateActionRequest;
use App\Http\Integrations\Zitadel\Requests\CreateOidcAppRequest;
use App\Http\Integrations\Zitadel\Requests\CreateProjectRequest;
use App\Http\Integrations\Zitadel\Requests\CreateProjectRoleRequest;
use App\Http\Integrations\Zitadel\Requests\GetFlowRequest;
use App\Http\Integrations\Zitadel\Requests\GetProjectRequest;
use App\Http\Integrations\Zitadel\Requests\SearchActionsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectAppsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectRolesRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectsRequest;
use App\Http\Integrations\Zitadel\Requests\SetFlowTriggerActionsRequest;
use App\Support\Labels;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('meet:wire labels the Synapse Meet and config Secrets with ToolInstance labels', function (): void {
    $registry = json_encode(['_system' => [
        'key' => 'LK_system',
        'secret' => 'systemsecret',
        'roomPrefix' => 'system-',
        'webhookUrl' => null,
    ]]);
    $homeserver = base64_encode("server_name: \"chat.example.com\"\nreport_stats: false\n");

    Process::fake([
        '*-l luchtech.dev/tool=livekit --no-headers*' => Process::result(output: 'livekit-meet-example-com 1/1'),
        '*get deployment -l luchtech.dev/tool=matrix*' => Process::result(output: 'synapse-chat-example-com 1/1'),
        '*get deployment synapse-chat-example-com*' => Process::result(output: 'synapse-chat-example-com 1/1'),
        '*luchtech.dev/component=lk-jwt*' => Process::result(output: ''),
        '*get secret livekit-secrets-meet-example-com*' => Process::result(output: base64_encode((string) $registry)),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'livekit', 'host' => 'meet.example.com', 'instance' => 'meet-example-com'],
            ['tool' => 'matrix', 'host' => 'chat.example.com', 'instance' => 'chat-example-com'],
        ]))),
        '*get secret synapse-config-chat-example-com*' => Process::result(output: $homeserver),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('meet:wire local --tool=matrix --no-interaction')
        ->assertExitCode(0);

    Process::assertRan(fn ($job): bool => ($secret = appliedSecret($job)) !== null
        && $secret['name'] === 'synapse-meet-chat-example-com'
        && ($secret['labels'][Labels::TOOL] ?? null) === ClusterTool::MATRIX->value
        && ($secret['labels'][Labels::INSTANCE] ?? null) === 'chat-example-com');

    Process::assertRan(fn ($job): bool => ($secret = appliedSecret($job)) !== null
        && $secret['name'] === 'synapse-config-chat-example-com'
        && ($secret['labels'][Labels::TOOL] ?? null) === ClusterTool::MATRIX->value
        && ($secret['labels'][Labels::INSTANCE] ?? null) === 'chat-example-com');
});

test('mail:wire labels the cached Stalwart sender Secret and target SMTP Secret', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'stalwart', 'host' => 'mail.example.com', 'instance' => 'mail-example-com'],
            ['tool' => 'directus', 'host' => 'cms.example.com', 'instance' => 'cms-example-com'],
        ]))),
        '*get secret stalwart-sender-mail-example-com*' => Process::result(output: ''),
        '*get deployment directus-cms-example-com*' => Process::result(output: 'directus-cms-example-com 1/1'),
        '*luchtech.dev/tool=stalwart*' => Process::result(output: 'stalwart-mail-example-com 1/1'),
        '*exec deploy/stalwart-mail-example-com*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*apply -f -*' => Process::result(output: 'applied'),
        '*set env deployment/directus-cms-example-com*' => Process::result(output: 'updated'),
        '*rollout restart deployment/directus-cms-example-com*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:wire local --tool=directus --sender=noreply@example.com --app-password=secret')
        ->assertExitCode(0);

    Process::assertRan(fn ($job): bool => ($secret = appliedSecret($job)) !== null
        && $secret['name'] === 'stalwart-sender-mail-example-com'
        && ($secret['labels'][Labels::TOOL] ?? null) === ClusterTool::STALWART->value
        && ($secret['labels'][Labels::INSTANCE] ?? null) === 'mail-example-com');

    Process::assertRan(fn ($job): bool => ($secret = appliedSecret($job)) !== null
        && $secret['name'] === 'directus-smtp-cms-example-com'
        && ($secret['labels'][Labels::TOOL] ?? null) === ClusterTool::DIRECTUS->value
        && ($secret['labels'][Labels::INSTANCE] ?? null) === 'cms-example-com');
});

test('sso:wire labels Zitadel app registration Secrets and target OIDC Secrets', function (): void {
    Saloon::fake([
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        CreateOidcAppRequest::class => MockResponse::make([
            'appId' => 'app-1',
            'clientId' => 'cid-1',
            'clientSecret' => 'csec-1',
        ]),
        GetProjectRequest::class => MockResponse::make(['project' => ['id' => 'proj-1', 'name' => 'LaraKube RBAC', 'projectRoleAssertion' => true, 'projectRoleCheck' => true]]),
        SearchProjectRolesRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRoleRequest::class => MockResponse::make([]),
    ]);

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode((string) json_encode([
            ssoRegistryRow(),
            ['tool' => 'grafana', 'host' => 'monitor.example.com', 'instance' => 'monitor-example-com'],
        ]))),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('pat-token')),
        '*get deployment zitadel-sso-example-com*' => Process::result(output: 'zitadel-sso-example-com 1/1'),
        '*get deployment*grafana*' => Process::result(output: 'grafana-monitor-example-com 1/1'),
        '*apply -f -*' => Process::result(output: 'applied'),
        '*set env deployment/*grafana*' => Process::result(output: 'updated'),
        '*rollout restart deployment/*grafana*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('sso:wire', ['--tool' => 'grafana', '--no-interaction' => true, '--context' => 'ctx'])
        ->assertExitCode(0);

    Process::assertRan(fn ($job): bool => ($secret = appliedSecret($job)) !== null
        && $secret['name'] === 'grafana-sso-monitor-example-com'
        && ($secret['labels'][Labels::TOOL] ?? null) === ClusterTool::GRAFANA->value
        && ($secret['labels'][Labels::INSTANCE] ?? null) === 'monitor-example-com');

    Process::assertRan(fn ($job): bool => ($secret = appliedSecret($job)) !== null
        && str_starts_with($secret['name'], 'grafana-oidc')
        && ($secret['labels'][Labels::TOOL] ?? null) === ClusterTool::GRAFANA->value
        && ($secret['labels'][Labels::INSTANCE] ?? null) === 'monitor-example-com');
});

test('secrets:wire labels VaultDynamicSecret, ExternalSecret, and target Secret template', function (): void {
    $rendered = view('k8s.openbao.eso-db-static', [
        'namespace' => 'larakube-shared',
        'openbaoServer' => 'http://openbao.larakube-secrets.svc.cluster.local:8200',
        'secretName' => 'documenso-secrets-sign-example-com',
        'roleName' => 'documenso_sign_example_com',
        'passwordKey' => 'db-password',
        'labels' => [
            Labels::TOOL => ClusterTool::DOCUMENSO->value,
            Labels::INSTANCE => 'sign-example-com',
        ],
    ])->render();

    $expected = [Labels::TOOL => 'documenso', Labels::INSTANCE => 'sign-example-com'];
    [$generator, $externalSecret] = array_map(
        fn (string $doc): array => Symfony\Component\Yaml\Yaml::parse($doc),
        preg_split('/^---$/m', $rendered) ?: [],
    );

    expect($generator['metadata']['labels'])->toBe($expected)
        ->and($externalSecret['metadata']['labels'])->toBe($expected)
        ->and($externalSecret['spec']['target']['template']['metadata']['labels'])->toBe($expected);
});
