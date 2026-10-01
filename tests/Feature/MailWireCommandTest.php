<?php

use App\Http\Integrations\Zitadel\Requests\ActivateEmailProviderRequest;
use App\Http\Integrations\Zitadel\Requests\CreateSmtpProviderRequest;
use App\Http\Integrations\Zitadel\Requests\SearchEmailProvidersRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:wire --forget clears the cached sender and exits (no Stalwart needed)', function (): void {
    Process::fake([
        '*delete secret stalwart-sender*' => Process::result(output: 'secret "stalwart-sender" deleted'),
    ]);

    $this->artisan('mail:wire --forget')
        ->assertExitCode(0)
        ->expectsOutputToContain('Cleared cached sender credentials');

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete secret stalwart-sender'));
});

test('mail:wire --tool=sso configures Zitadel SMTP via API', function (): void {
    ssoRegistered();
    Saloon::fake([
        SearchEmailProvidersRequest::class => MockResponse::make(['result' => []]),
        CreateSmtpProviderRequest::class => MockResponse::make(['id' => 'smtp-123']),
        ActivateEmailProviderRequest::class => MockResponse::make([], 200),
    ]);

    Process::fake([
        '*get secret stalwart-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('pat-token')),
        '*get deployment zitadel-sso-example-com*' => Process::result(output: 'zitadel-sso-example-com   1/1   1   1   10d'),
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
    ]);

    $this->artisan('mail:wire local --tool=sso')
        ->expectsOutputToContain('Wired to Stalwart: Identity Provider / SSO (Zitadel)');
});

test('mail:wire local --tool=data configures Directus SMTP via deployment secret', function (): void {
    Process::fake([
        '*get secret stalwart-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get deployment directus*' => Process::result(output: 'directus   1/1   1   1   10d'),
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*apply -f -*' => Process::result(output: 'applied'),
        '*set env deployment/directus*' => Process::result(output: 'updated'),
        '*rollout restart deployment/directus*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:wire local --tool=data')
        ->expectsOutputToContain('Wired to Stalwart: Headless CMS & Data API (PocketBase or Directus)');
});

test('mail:wire local --tool=data configures PocketBase SMTP, not Directus, on a PocketBase-only install', function (): void {
    // Regression test for the concrete bug this overhaul exists to fix:
    // resolveToolEngine() used to only special-case CHAT — every other
    // multi-engine tool (DATA) got a null $engine, and smtpEnv(null, ...)
    // for DATA falls through to Directus's schema regardless of what's
    // actually installed. A PocketBase-only install would previously have
    // tried to patch a nonexistent directus Deployment.
    Process::fake([
        '*get secret stalwart-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get deployment pocketbase*' => Process::result(output: 'pocketbase   1/1   1   1   10d'),
        '*get deployment directus*' => Process::result(output: '', exitCode: 1),
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*apply -f -*' => Process::result(output: 'applied'),
        '*set env deployment/pocketbase*' => Process::result(output: 'updated'),
        '*rollout restart deployment/pocketbase*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:wire local --tool=data')
        ->expectsOutputToContain('Wired to Stalwart: Headless CMS & Data API (PocketBase or Directus)');

    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/pocketbase')
        && str_contains($process->command, '--from=secret/pocketbase-smtp'));
    Process::assertRan(fn ($process) => str_starts_with(appliedSecret($process)['name'] ?? '', 'pocketbase-smtp')
        && isset(appliedSecret($process)['data']['POCKETBASE_SMTP_HOST']));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'deployment/directus'));
});

test('mail:wire local --tool=design configures Penpot SMTP via deployment secret', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'penpot', 'instance' => 'design-example-com', 'host' => 'design.example.com'],
        ]))),
        '*get secret stalwart-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get deployment penpot-backend-design-example-com*' => Process::result(output: 'penpot-backend-design-example-com   1/1   1   1   10d'),
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*apply -f -*' => Process::result(output: 'applied'),
        '*set env deployment/penpot-backend-design-example-com*' => Process::result(output: 'updated'),
        '*set env deployment/penpot-frontend-design-example-com*' => Process::result(output: 'updated'),
        '*rollout restart deployment/penpot-backend-design-example-com*' => Process::result(output: 'restarted'),
        '*rollout restart deployment/penpot-frontend-design-example-com*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:wire local --tool=design')
        ->expectsOutputToContain('Wired to Stalwart: Design & Prototyping (Penpot)');

    Process::assertRan(fn ($process) => isset(appliedSecret($process)['data']['PENPOT_SMTP_HOST']));
});

test('mail:wire local --tool=<errors|glitchtip> composes GlitchTip EMAIL_URL and patches the worker too, whichever name is given', function (string $toolName): void {
    Process::fake([
        '*get secret stalwart-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'errors', 'instance' => 'errors-example-com', 'host' => 'errors.example.com'],
        ]))),
        '*get deployment glitchtip-errors-example-com*' => Process::result(output: 'glitchtip-errors-example-com   1/1   1   1   10d'),
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*apply -f -*' => Process::result(output: 'applied'),
        '*set env deployment/glitchtip-errors-example-com*' => Process::result(output: 'updated'),
        '*set env deployment/glitchtip-worker-errors-example-com*' => Process::result(output: 'updated'),
        '*rollout restart deployment/glitchtip-errors-example-com*' => Process::result(output: 'restarted'),
        '*rollout restart deployment/glitchtip-worker-errors-example-com*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan("mail:wire local --tool={$toolName}")
        ->expectsOutputToContain('Wired to Stalwart:');

    // GlitchTip reads one composed django-environ URL, not per-host vars —
    // credentials must be percent-encoded (the sender's @ would break it).
    Process::assertRan(fn ($process) => (appliedSecret($process)['name'] ?? '') === 'glitchtip-smtp-errors-example-com'
        && isset(appliedSecret($process)['data']['DEFAULT_FROM_EMAIL'])
        && str_starts_with(appliedSecret($process)['data']['EMAIL_URL'] ?? '', 'smtp+ssl://noreply%40luchtech.dev:noreply%40luchtech.dev@'));

    // The worker sends the actual alert emails, so it shares the primary's
    // SMTP secret via also_patch.
    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/glitchtip-worker-errors-example-com')
        && str_contains($process->command, '--from=secret/glitchtip-smtp-errors-example-com'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/glitchtip-worker-errors-example-com'));
})->with(['errors', 'glitchtip']);

test('mail:wire local --tool=crm resolves the real host-derived instance from the registry and patches the worker too', function (): void {
    // Regression: CRM has no 'main' deployment at all (pure host-derived
    // instance naming, see ClusterTool::CRM->instanceSlugFromHost()) — this
    // pins that mail:wire finds it via the tool registry instead of probing
    // the never-existing unsuffixed 'twenty' deployment.
    Process::fake([
        '*get secret stalwart-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ssoRegistryRow(),
            ['tool' => 'crm', 'instance' => 'crm-luchtech-dev', 'host' => 'crm.luchtech.dev'],
        ]))),
        '*get deployment twenty-crm-luchtech-dev*' => Process::result(output: 'twenty-crm-luchtech-dev   1/1   1   1   10d'),
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*apply -f -*' => Process::result(output: 'applied'),
        '*set env deployment/twenty-crm-luchtech-dev*' => Process::result(output: 'updated'),
        '*set env deployment/twenty-worker-crm-luchtech-dev*' => Process::result(output: 'updated'),
        '*rollout restart deployment/twenty-crm-luchtech-dev*' => Process::result(output: 'restarted'),
        '*rollout restart deployment/twenty-worker-crm-luchtech-dev*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:wire local --tool=crm')
        ->expectsOutputToContain('Wired to Stalwart: CRM (Twenty)');

    Process::assertRan(fn ($process) => str_starts_with(appliedSecret($process)['name'] ?? '', 'twenty-smtp-crm-luchtech-dev')
        && isset(appliedSecret($process)['data']['EMAIL_SMTP_HOST']));
    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/twenty-worker-crm-luchtech-dev')
        && str_contains($process->command, '--from=secret/twenty-smtp-crm-luchtech-dev'));

    // The never-existing unsuffixed name must never be targeted.
    Process::assertNotRan(fn ($process) => preg_match('#deployment/twenty(-worker)?(\s|$)#', $process->command) === 1);
});

test('mail:wire --domain targets that host\'s instance, even pasted as a URL', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ssoRegistryRow(),
            ['tool' => 'flow', 'instance' => 'flow-example-com', 'host' => 'flow.example.com', 'engine' => 'n8n'],
        ]))),
        '*get secret stalwart-sender*' => Process::result(output: base64_encode('noreply@example.com')),
        '*get deployment n8n-flow-example-com*' => Process::result(output: 'n8n-flow-example-com   1/1   1   1   1d'),
        '*get deployment windmill-*' => Process::result(output: '', exitCode: 1),
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('mail:wire local --tool=flow --domain=https://Flow.Example.com/')
        ->expectsOutputToContain('Wired to Stalwart');

    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/n8n-flow-example-com')
        && str_contains($process->command, 'secret/n8n-smtp-flow-example-com'));
});

function mailWireRegistryFakes(array $rows): array
{
    return [
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode($rows))),
        '*get secret stalwart-sender*' => Process::result(output: base64_encode('noreply@example.com')),
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*get deployment flow-*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(output: ''),
    ];
}

test('mail:wire --domain for a host with no instance names the hosts that have one', function (): void {
    Process::fake(mailWireRegistryFakes([
        ['tool' => 'flow', 'instance' => 'flow-example-com', 'host' => 'flow.example.com', 'engine' => 'n8n'],
    ]));

    $this->artisan('mail:wire production --tool=flow --domain=typo.example.com')
        ->expectsOutputToContain('has no instance at typo.example.com')
        ->expectsOutputToContain('flow.example.com');
});

test('mail:wire for a tool with no instance at all says how to install it', function (): void {
    Process::fake(mailWireRegistryFakes([]));

    $this->artisan('mail:wire production --tool=flow --domain=flow.example.com')
        ->expectsOutputToContain('is not installed. Run `larakube n8n:init production` first.');
});
