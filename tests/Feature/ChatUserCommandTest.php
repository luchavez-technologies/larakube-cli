<?php

use App\Http\Integrations\Matrix\Requests\GetRegisterNonceRequest;
use App\Http\Integrations\Matrix\Requests\RegisterUserRequest;
use App\Http\Integrations\Matrix\Requests\SetUserAccountRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function chatUserBaseProcessFakes(): array
{
    return [
        '*get deployment chat-synapse*' => Process::result(output: 'chat-synapse   1/1   1   1   10d'),
        '*get secret chat-secrets*admin-access-token*' => Process::result(output: '', exitCode: 1),
        '*get secret chat-secrets*registration-secret*' => Process::result(output: base64_encode('shared-registration-secret')),
        '*get secret chat-secrets*automation-password*' => Process::result(output: '', exitCode: 1),
        '*patch secret chat-secrets*' => Process::result(output: 'secret/chat-secrets patched'),
    ];
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('chat:user is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('chat:user');
});

test('chat:user requires installed chat', function (): void {
    Process::fake(['*get deployment chat-synapse*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('chat:user', ['--username' => 'alice', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Chat is not installed');
});

test('chat:user bootstraps the automation admin (shared-secret register) then creates the account', function (): void {
    Process::fake(chatUserBaseProcessFakes());

    Saloon::fake([
        GetRegisterNonceRequest::class => MockResponse::make(['nonce' => 'nonce-abc']),
        RegisterUserRequest::class => MockResponse::make(['access_token' => 'admin-token-xyz']),
        SetUserAccountRequest::class => MockResponse::make(['name' => '@alice:chat.luchtech.dev'], 201),
    ]);

    $this->artisan('chat:user', ['--username' => 'alice', '--password' => 'alicepw', '--display-name' => 'Alice', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Account created')
        ->expectsOutputToContain('@alice:');

    Saloon::assertSent(fn ($request) => $request instanceof SetUserAccountRequest
        && $request->body()->get('password') === 'alicepw'
        && $request->body()->get('displayname') === 'Alice');
});

test('chat:user reuses a cached admin token without re-bootstrapping', function (): void {
    Process::fake([
        '*get deployment chat-synapse*' => Process::result(output: 'chat-synapse   1/1   1   1   10d'),
        '*get secret chat-secrets*admin-access-token*' => Process::result(output: base64_encode('cached-admin-token')),
    ]);

    Saloon::fake([
        GetRegisterNonceRequest::class => MockResponse::make(['nonce' => 'should-not-be-used']),
        SetUserAccountRequest::class => MockResponse::make(['name' => '@bob:chat.luchtech.dev'], 200),
    ]);

    $this->artisan('chat:user', ['--username' => 'bob', '--no-interaction' => true])
        ->expectsQuestion('Display name', '')
        ->assertExitCode(0)
        ->expectsOutputToContain('Account updated');

    Saloon::assertNotSent(GetRegisterNonceRequest::class);
    Saloon::assertSent(fn ($request, $response) => $request instanceof SetUserAccountRequest
        && $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer cached-admin-token');
});
