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
        '*delete secret mail-sender*' => Process::result(output: 'secret "mail-sender" deleted'),
    ]);

    $this->artisan('mail:wire --forget')
        ->assertExitCode(0)
        ->expectsOutputToContain('Cleared cached sender credentials');

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete secret mail-sender'));
});

test('mail:wire --tool=sso configures Zitadel SMTP via API', function (): void {
    Saloon::fake([
        SearchEmailProvidersRequest::class => MockResponse::make(['result' => []]),
        CreateSmtpProviderRequest::class => MockResponse::make(['id' => 'smtp-123']),
        ActivateEmailProviderRequest::class => MockResponse::make([], 200),
    ]);

    Process::fake([
        '*get secret mail-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('pat-token')),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/mail-stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*create secret generic mail-sender*' => Process::result(output: 'created'),
    ]);

    $this->artisan('mail:wire local --tool=sso')
        ->expectsOutputToContain('Wired to Stalwart: Identity Provider / SSO (Zitadel)');
});

test('mail:wire local --tool=data configures Directus SMTP via deployment secret', function (): void {
    Process::fake([
        '*get secret mail-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get deployment data-directus*' => Process::result(output: 'data-directus   1/1   1   1   10d'),
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/mail-stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*create secret generic mail-sender*' => Process::result(output: 'created'),
        '*create secret generic data-smtp*' => Process::result(output: 'created'),
        '*set env deployment/data-directus*' => Process::result(output: 'updated'),
        '*rollout restart deployment/data-directus*' => Process::result(output: 'restarted'),
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
    // tried to patch a nonexistent data-directus Deployment.
    Process::fake([
        '*get secret mail-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get deployment data-pocketbase*' => Process::result(output: 'data-pocketbase   1/1   1   1   10d'),
        '*get deployment data-directus*' => Process::result(output: '', exitCode: 1),
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/mail-stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*create secret generic mail-sender*' => Process::result(output: 'created'),
        '*create secret generic data-smtp*' => Process::result(output: 'created'),
        '*set env deployment/data-pocketbase*' => Process::result(output: 'updated'),
        '*rollout restart deployment/data-pocketbase*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:wire local --tool=data')
        ->expectsOutputToContain('Wired to Stalwart: Headless CMS & Data API (PocketBase or Directus)');

    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/data-pocketbase')
        && str_contains($process->command, '--from=secret/data-smtp'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic data-smtp')
        && str_contains($process->command, 'POCKETBASE_SMTP_HOST'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'deployment/data-directus'));
});

test('mail:wire local --tool=design configures Penpot SMTP via deployment secret', function (): void {
    Process::fake([
        '*get secret mail-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get deployment design-penpot-backend*' => Process::result(output: 'design-penpot-backend   1/1   1   1   10d'),
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/mail-stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*create secret generic mail-sender*' => Process::result(output: 'created'),
        '*create secret generic design-smtp*' => Process::result(output: 'created'),
        '*set env deployment/design-penpot-backend*' => Process::result(output: 'updated'),
        '*set env deployment/design-penpot-frontend*' => Process::result(output: 'updated'),
        '*rollout restart deployment/design-penpot-backend*' => Process::result(output: 'restarted'),
        '*rollout restart deployment/design-penpot-frontend*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:wire local --tool=design')
        ->expectsOutputToContain('Wired to Stalwart: Design & Prototyping (Penpot)');

    Process::assertRan(fn ($process) => str_contains($process->command, 'PENPOT_SMTP_HOST'));
});

test('mail:wire local --tool=errors composes GlitchTip EMAIL_URL and patches the worker too', function (): void {
    Process::fake([
        '*get secret mail-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get deployment glitchtip-web*' => Process::result(output: 'glitchtip-web   1/1   1   1   10d'),
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/mail-stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*create secret generic mail-sender*' => Process::result(output: 'created'),
        '*create secret generic glitchtip-smtp*' => Process::result(output: 'created'),
        '*set env deployment/glitchtip-web*' => Process::result(output: 'updated'),
        '*set env deployment/glitchtip-worker*' => Process::result(output: 'updated'),
        '*rollout restart deployment/glitchtip-web*' => Process::result(output: 'restarted'),
        '*rollout restart deployment/glitchtip-worker*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:wire local --tool=errors')
        ->expectsOutputToContain('Wired to Stalwart: Error Tracking (GlitchTip)');

    // GlitchTip reads one composed django-environ URL, not per-host vars —
    // credentials must be percent-encoded (the sender's @ would break it).
    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic glitchtip-smtp')
        && str_contains($process->command, 'EMAIL_URL')
        && str_contains($process->command, 'DEFAULT_FROM_EMAIL')
        && str_contains($process->command, 'smtp+ssl://noreply%40luchtech.dev:noreply%40luchtech.dev@'));

    // The worker sends the actual alert emails, so it shares the primary's
    // SMTP secret via also_patch.
    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/glitchtip-worker')
        && str_contains($process->command, '--from=secret/glitchtip-smtp'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/glitchtip-worker'));
});

test('mail:wire local --tool=crm resolves the real host-derived instance from the registry and patches the worker too', function (): void {
    // Regression: CRM has no 'main' deployment at all (pure host-derived
    // instance naming, see ClusterTool::CRM->instanceSlugFromHost()) — this
    // pins that mail:wire finds it via the tool registry instead of probing
    // the never-existing unsuffixed 'crm-twenty' deployment.
    Process::fake([
        '*get secret mail-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'crm', 'instance' => 'crm-luchtech-dev', 'host' => 'crm.luchtech.dev'],
        ]))),
        '*get deployment crm-twenty-crm-luchtech-dev*' => Process::result(output: 'crm-twenty-crm-luchtech-dev   1/1   1   1   10d'),
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/mail-stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*create secret generic mail-sender*' => Process::result(output: 'created'),
        '*create secret generic crm-smtp-crm-luchtech-dev*' => Process::result(output: 'created'),
        '*set env deployment/crm-twenty-crm-luchtech-dev*' => Process::result(output: 'updated'),
        '*set env deployment/crm-twenty-worker-crm-luchtech-dev*' => Process::result(output: 'updated'),
        '*rollout restart deployment/crm-twenty-crm-luchtech-dev*' => Process::result(output: 'restarted'),
        '*rollout restart deployment/crm-twenty-worker-crm-luchtech-dev*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:wire local --tool=crm')
        ->expectsOutputToContain('Wired to Stalwart: CRM (Twenty)');

    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic crm-smtp-crm-luchtech-dev')
        && str_contains($process->command, 'EMAIL_SMTP_HOST'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/crm-twenty-worker-crm-luchtech-dev')
        && str_contains($process->command, '--from=secret/crm-smtp-crm-luchtech-dev'));

    // The never-existing unsuffixed name must never be targeted.
    Process::assertNotRan(fn ($process) => preg_match('#deployment/crm-twenty(-worker)?(\s|$)#', $process->command) === 1);
});
