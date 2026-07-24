<?php

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
    Illuminate\Support\Facades\Http::fake(function ($request) {
        if (str_contains($request->url(), '_activate')) {
            return Illuminate\Support\Facades\Http::response([], 200);
        }

        return Illuminate\Support\Facades\Http::response(['id' => 'smtp-123'], 200);
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
