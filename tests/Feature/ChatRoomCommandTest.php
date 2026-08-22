<?php

use App\Http\Integrations\Matrix\Requests\CreateRoomRequest;
use App\Http\Integrations\Matrix\Requests\GetJoinedMembersRequest;
use App\Http\Integrations\Matrix\Requests\GetRoomByAliasRequest;
use App\Http\Integrations\Matrix\Requests\InviteToRoomRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function chatRoomBaseProcessFakes(): array
{
    return [
        '*get deployment chat-synapse*' => Process::result(output: 'chat-synapse   1/1   1   1   10d'),
        '*get secret chat-secrets*admin-access-token*' => Process::result(output: base64_encode('cached-admin-token')),
    ];
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

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
    Saloon::fake([
        GetRoomByAliasRequest::class => MockResponse::make(['errcode' => 'M_NOT_FOUND'], 404),
        CreateRoomRequest::class => MockResponse::make(['room_id' => '!room1:luchtech.dev']),
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

    Saloon::assertSent(fn ($request) => $request instanceof CreateRoomRequest
        && $request->body()->get('room_alias_name') === 'partner-team'
        && $request->body()->get('name') === 'Partner Team'
        && $request->body()->get('invite') === ['@alice:luchtech.dev', '@bob:luchtech.dev']);
});

test('chat:room reuses an existing room and only invites members not already joined', function (): void {
    Process::fake(chatRoomBaseProcessFakes());
    Saloon::fake([
        GetRoomByAliasRequest::class => MockResponse::make(['room_id' => '!room1:luchtech.dev']),
        GetJoinedMembersRequest::class => MockResponse::make(['joined' => ['@alice:luchtech.dev' => ['display_name' => 'Alice']]]),
        InviteToRoomRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('chat:room', [
        '--alias' => 'partner-team',
        '--invite' => ['@alice:luchtech.dev', '@bob:luchtech.dev'],
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Room already exists');

    Saloon::assertSent(fn ($request) => $request instanceof InviteToRoomRequest
        && $request->body()->get('user_id') === '@bob:luchtech.dev');
    Saloon::assertNotSent(fn ($request) => $request instanceof InviteToRoomRequest
        && $request->body()->get('user_id') === '@alice:luchtech.dev');
});
