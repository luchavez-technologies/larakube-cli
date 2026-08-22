<?php

use App\Commands\Sso\SsoCreateCommand;
use App\Http\Integrations\Zitadel\Requests\CreateUserRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUsersRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

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

    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => []]),
        CreateUserRequest::class => MockResponse::make(['userId' => 'new-uid-123']),
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

    Saloon::assertSent(fn ($request) => $request instanceof CreateUserRequest
        && $request->body()->get('email')['email'] === 'client@acme.com');
});

test('sso:create reports if user already exists', function (): void {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get ingress sso-zitadel*' => Process::result(output: 'sso.example.com'),
    ]);

    Saloon::fake([
        SearchUsersRequest::class => MockResponse::make(['result' => [['userId' => 'existing-uid-456']]]),
    ]);

    $this->artisan(SsoCreateCommand::class, [
        'environment' => 'local',
        '--email' => 'existing@acme.com',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain("Zitadel SSO user account 'existing@acme.com' already exists");
});
