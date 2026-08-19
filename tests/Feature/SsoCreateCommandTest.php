<?php

use App\Commands\Sso\SsoCreateCommand;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('sso:create is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:create');
});

test('sso:create provisions a new human user in Zitadel', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get ingress sso-zitadel*' => Process::result(output: 'sso.example.com'),
    ]);

    Http::fake([
        '*/v2/users' => Http::response(['result' => []]),
        '*/v2/users/human' => Http::response(['userId' => 'new-uid-123']),
    ]);

    $this->artisan(SsoCreateCommand::class, [
        'environment' => 'local',
        '--email' => 'client@acme.com',
        '--name' => 'Client User',
        '--password' => 'SecretPassword123!',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Zitadel SSO user account created successfully')
        ->expectsOutputToContain('client@acme.com');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/users/human')
        && $request['email']['email'] === 'client@acme.com');
});

test('sso:create reports if user already exists', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get ingress sso-zitadel*' => Process::result(output: 'sso.example.com'),
    ]);

    Http::fake([
        '*/v2/users' => Http::response(['result' => [['userId' => 'existing-uid-456']]]),
    ]);

    $this->artisan(SsoCreateCommand::class, [
        'environment' => 'local',
        '--email' => 'existing@acme.com',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain("Zitadel SSO user account 'existing@acme.com' already exists");
});
