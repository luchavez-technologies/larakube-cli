<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('mail:wire --forget clears the cached sender and exits (no Stalwart needed)', function () {
    Process::fake([
        '*delete secret mail-sender*' => Process::result(output: 'secret "mail-sender" deleted'),
    ]);

    $this->artisan('mail:wire --forget')
        ->assertExitCode(0)
        ->expectsOutputToContain('Cleared cached sender credentials');

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete secret mail-sender'));
});

test('mail:wire --tool=sso configures Zitadel SMTP via API', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '_activate')) {
            return Http::response([], 200);
        }

        return Http::response(['id' => 'smtp-123'], 200);
    });

    Process::fake([
        '*get secret mail-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('pat-token')),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*create secret generic mail-sender*' => Process::result(output: 'created'),
    ]);

    $this->artisan('mail:wire local --tool=sso')
        ->expectsOutputToContain('Wired to Stalwart: Identity Provider / SSO (Zitadel)');
});

test('mail:wire local --tool=data configures Directus SMTP via deployment secret', function () {
    Process::fake([
        '*get secret mail-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get deployment data-directus*' => Process::result(output: 'data-directus   1/1   1   1   10d'),
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*create secret generic mail-sender*' => Process::result(output: 'created'),
        '*create secret generic data-smtp*' => Process::result(output: 'created'),
        '*set env deployment/data-directus*' => Process::result(output: 'updated'),
        '*rollout restart deployment/data-directus*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:wire local --tool=data')
        ->expectsOutputToContain('Wired to Stalwart: Headless CMS & Data API (PocketBase or Directus)');
});

test('mail:wire local --tool=design configures Penpot SMTP via deployment secret', function () {
    Process::fake([
        '*get secret mail-sender*' => Process::result(output: base64_encode('noreply@luchtech.dev')),
        '*get deployment design-penpot-backend*' => Process::result(output: 'design-penpot-backend   1/1   1   1   10d'),
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*exec deploy/stalwart*' => Process::result(output: "235 2.7.0 Authentication succeeded.\n"),
        '*create secret generic mail-sender*' => Process::result(output: 'created'),
        '*create secret generic design-penpot-smtp*' => Process::result(output: 'created'),
        '*set env deployment/design-penpot-backend*' => Process::result(output: 'updated'),
        '*rollout restart deployment/design-penpot-backend*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:wire local --tool=design')
        ->expectsOutputToContain('Wired to Stalwart: Design & Prototyping (Penpot)');

    Process::assertRan(fn ($process) => str_contains($process->command, 'PENPOT_SMTP_HOST'));
});
