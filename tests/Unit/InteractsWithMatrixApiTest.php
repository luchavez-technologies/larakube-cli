<?php

use App\Http\Integrations\Matrix\Requests\GetRegisterNonceRequest;
use App\Http\Integrations\Matrix\Requests\InviteToRoomRequest;
use App\Http\Integrations\Matrix\Requests\LoginRequest;
use App\Http\Integrations\Matrix\Requests\RegisterUserRequest;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithMatrixApi;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function matrixApiHarness(): object
{
    return new class
    {
        use InteractsWithChat, InteractsWithMatrixApi;

        public function adminToken(string $kubectl, string $ns, string $host): ?string
        {
            return $this->matrixAdminToken($kubectl, $ns, $host);
        }

        public function inviteToRoom(string $host, string $token, string $roomId, string $userId): bool
        {
            return $this->matrixInviteToRoom($host, $token, $roomId, $userId);
        }
    };
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('matrixAdminToken falls back to a password login when shared-secret registration says the user already exists', function (): void {
    Process::fake([
        '*get secret chat-secrets*admin-access-token*' => Process::result(output: '', exitCode: 1),
        '*get secret chat-secrets*registration-secret*' => Process::result(output: base64_encode('shared-secret')),
        '*get secret chat-secrets*automation-password*' => Process::result(output: base64_encode('cached-password')),
        '*patch secret chat-secrets*' => Process::result(output: 'patched'),
    ]);
    Saloon::fake([
        GetRegisterNonceRequest::class => MockResponse::make(['nonce' => 'nonce-abc']),
        RegisterUserRequest::class => MockResponse::make(['errcode' => 'M_USER_IN_USE', 'error' => 'User ID already taken.'], 400),
        LoginRequest::class => MockResponse::make(['access_token' => 'token-from-login']),
    ]);

    expect(matrixApiHarness()->adminToken('kubectl', 'larakube-shared', 'chat.example.com'))->toBe('token-from-login');

    Saloon::assertSent(fn ($request) => $request instanceof LoginRequest
        && $request->body()->get('password') === 'cached-password');
});

test('matrixAdminToken returns null when both registration and the login fallback fail', function (): void {
    Process::fake([
        '*get secret chat-secrets*admin-access-token*' => Process::result(output: '', exitCode: 1),
        '*get secret chat-secrets*registration-secret*' => Process::result(output: base64_encode('shared-secret')),
        '*get secret chat-secrets*automation-password*' => Process::result(output: '', exitCode: 1),
    ]);
    Saloon::fake([
        GetRegisterNonceRequest::class => MockResponse::make(['nonce' => 'nonce-abc']),
        RegisterUserRequest::class => MockResponse::make(['errcode' => 'M_USER_IN_USE', 'error' => 'User ID already taken.'], 400),
        LoginRequest::class => MockResponse::make(['errcode' => 'M_FORBIDDEN'], 403),
    ]);

    expect(matrixApiHarness()->adminToken('kubectl', 'larakube-shared', 'chat.example.com'))->toBeNull();
});

test('matrixInviteToRoom treats "already in the room" as success, not a failure', function (): void {
    Saloon::fake([
        InviteToRoomRequest::class => MockResponse::make(['errcode' => 'M_FORBIDDEN', 'error' => 'is already in the room.'], 403),
    ]);

    expect(matrixApiHarness()->inviteToRoom('chat.example.com', 'token', '!room:example.com', '@alice:example.com'))->toBeTrue();
});

test('matrixInviteToRoom returns false on a genuine failure', function (): void {
    Saloon::fake([
        InviteToRoomRequest::class => MockResponse::make(['errcode' => 'M_UNKNOWN', 'error' => 'Internal server error'], 500),
    ]);

    expect(matrixApiHarness()->inviteToRoom('chat.example.com', 'token', '!room:example.com', '@alice:example.com'))->toBeFalse();
});
