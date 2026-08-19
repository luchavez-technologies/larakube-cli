<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

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

    Http::fake([
        '*_synapse/admin/v1/register' => Http::sequence()
            ->push(['nonce' => 'nonce-abc'])
            ->push(['access_token' => 'admin-token-xyz']),
        '*_synapse/admin/v2/users/*' => Http::response(['name' => '@alice:chat.luchtech.dev'], 201),
    ]);

    $this->artisan('chat:user', ['--username' => 'alice', '--password' => 'alicepw', '--display-name' => 'Alice', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Account created')
        ->expectsOutputToContain('@alice:');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/_synapse/admin/v2/users/')
        && $request->method() === 'PUT'
        && $request['password'] === 'alicepw'
        && $request['displayname'] === 'Alice');
});

test('chat:user reuses a cached admin token without re-bootstrapping', function (): void {
    Process::fake([
        '*get deployment chat-synapse*' => Process::result(output: 'chat-synapse   1/1   1   1   10d'),
        '*get secret chat-secrets*admin-access-token*' => Process::result(output: base64_encode('cached-admin-token')),
    ]);

    Http::fake([
        '*_synapse/admin/v1/register' => Http::response(['nonce' => 'should-not-be-used']),
        '*_synapse/admin/v2/users/*' => Http::response(['name' => '@bob:chat.luchtech.dev'], 200),
    ]);

    $this->artisan('chat:user', ['--username' => 'bob', '--no-interaction' => true])
        ->expectsQuestion('Display name', '')
        ->assertExitCode(0)
        ->expectsOutputToContain('Account updated');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/_synapse/admin/v1/register'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/_synapse/admin/v2/users/')
        && $request->hasHeader('Authorization', 'Bearer cached-admin-token'));
});
