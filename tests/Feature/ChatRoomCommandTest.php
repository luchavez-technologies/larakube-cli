<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function chatRoomBaseProcessFakes(): array
{
    return [
        '*get deployment chat-synapse*' => Process::result(output: 'chat-synapse   1/1   1   1   10d'),
        '*get secret chat-secrets*admin-access-token*' => Process::result(output: base64_encode('cached-admin-token')),
    ];
}

test('chat:room is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('chat:room');
});

test('chat:room requires installed chat', function (): void {
    Process::fake(['*get deployment chat-synapse*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('chat:room', ['--alias' => 'partner-team', '--no-interaction' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Chat is not installed');
});

test('chat:room creates a new room and invites members in the same call', function (): void {
    Process::fake(chatRoomBaseProcessFakes());
    Http::fake([
        '*directory/room/*' => Http::response(['errcode' => 'M_NOT_FOUND'], 404),
        '*createRoom' => Http::response(['room_id' => '!room1:luchtech.dev']),
    ]);

    $this->artisan('chat:room', [
        '--alias' => 'partner-team',
        '--name' => 'Partner Team',
        '--invite' => ['@alice:luchtech.dev', '@bob:luchtech.dev'],
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Room created')
        ->expectsOutputToContain('!room1:luchtech.dev');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRoom')
        && $request['room_alias_name'] === 'partner-team'
        && $request['name'] === 'Partner Team'
        && $request['invite'] === ['@alice:luchtech.dev', '@bob:luchtech.dev']);
});

test('chat:room reuses an existing room and only invites members not already joined', function (): void {
    Process::fake(chatRoomBaseProcessFakes());
    Http::fake([
        '*directory/room/*' => Http::response(['room_id' => '!room1:luchtech.dev']),
        '*rooms/*/joined_members' => Http::response(['joined' => ['@alice:luchtech.dev' => ['display_name' => 'Alice']]]),
        '*rooms/*/invite' => Http::response([]),
    ]);

    $this->artisan('chat:room', [
        '--alias' => 'partner-team',
        '--invite' => ['@alice:luchtech.dev', '@bob:luchtech.dev'],
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Room already exists');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/invite')
        && $request['user_id'] === '@bob:luchtech.dev');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/invite')
        && ($request['user_id'] ?? null) === '@alice:luchtech.dev');
});
