<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Matrix Client-Server + Synapse Admin API, reached directly over Synapse's
 * own public ingress (chat.luchtech.dev already routes /_matrix and
 * /_synapse externally for Element/Cinny) — same shape as
 * InteractsWithZitadelApi, not a kubectl-exec proxy like Stalwart's JMAP.
 * Endpoint shapes confirmed live against a real Synapse instance
 * (2026-08-19): GET /_synapse/admin/v1/register returns a nonce, and a
 * malformed registration attempt (bad HMAC) 403s with "HMAC incorrect"
 * rather than a routing error — confirming the field names below, without
 * completing a real registration.
 */
trait InteractsWithMatrixApi
{
    use InteractsWithChat;

    /**
     * The larakube-automation admin's access token, bootstrapping the
     * account on first use via Synapse's shared-secret registration API
     * (registration_shared_secret, already stored as chat-secrets/
     * registration-secret by chat:init). Cached in chat-secrets so
     * subsequent calls skip straight to using it.
     *
     * On a re-run where the account already exists, shared-secret
     * registration 400s ("User ID already taken") — falls back to a normal
     * password login using the password cached alongside the token on
     * first bootstrap, mirroring Stalwart's recovery-admin fallback
     * posture without a dedicated break-glass command.
     */
    protected function matrixAdminToken(string $kubectl, string $ns, string $host): ?string
    {
        $existing = $this->readChatSecret($kubectl, $ns, 'admin-access-token');
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $secret = $this->readChatSecret($kubectl, $ns, 'registration-secret');
        if ($secret === null || $secret === '') {
            return null;
        }

        $username = 'larakube-automation';
        $password = $this->readChatSecret($kubectl, $ns, 'automation-password') ?? Str::password(32);

        $nonceResponse = Http::timeout(15)->get("https://{$host}/_synapse/admin/v1/register");
        if ($nonceResponse->failed()) {
            return null;
        }
        $nonce = $nonceResponse->json('nonce');
        if ($nonce === null) {
            return null;
        }

        $mac = hash_hmac('sha1', "{$nonce}\0{$username}\0{$password}\0admin", $secret);

        $register = Http::timeout(15)->post("https://{$host}/_synapse/admin/v1/register", [
            'nonce' => $nonce,
            'username' => $username,
            'password' => $password,
            'admin' => true,
            'mac' => $mac,
        ]);

        $token = null;
        if ($register->successful()) {
            $token = $register->json('access_token');
        } elseif (str_contains(strtolower((string) $register->body()), 'already taken')
            || str_contains(strtolower((string) $register->body()), 'user_in_use')) {
            // Already bootstrapped on a previous run — log in with the
            // cached password instead of re-registering.
            $login = Http::timeout(15)->post("https://{$host}/_matrix/client/v3/login", [
                'type' => 'm.login.password',
                'identifier' => ['type' => 'm.id.user', 'user' => $username],
                'password' => $password,
            ]);
            $token = $login->successful() ? $login->json('access_token') : null;
        }

        if ($token === null) {
            return null;
        }

        $this->storeChatSecret($kubectl, $ns, 'admin-access-token', $token);
        $this->storeChatSecret($kubectl, $ns, 'automation-password', $password);

        return $token;
    }

    /** The room id for a #alias:server_name, or null if it doesn't exist / on failure. */
    protected function matrixFindRoomByAlias(string $host, string $token, string $alias): ?string
    {
        $response = Http::withToken($token)->timeout(15)
            ->get("https://{$host}/_matrix/client/v3/directory/room/".rawurlencode($alias));

        return $response->successful() ? $response->json('room_id') : null;
    }

    /**
     * Create a new private room, inviting $inviteUserIds along in the same
     * call. $aliasLocalPart becomes the room's #alias:server_name — the
     * caller is responsible for checking matrixFindRoomByAlias() first
     * (rooms are otherwise createRoom-able with duplicate names, unlike
     * this codebase's other create-or-update idioms).
     *
     * @param  list<string>  $inviteUserIds  Full Matrix user IDs, e.g. @alice:luchtech.dev
     * @return string|null the new room's id, or null on failure
     */
    protected function matrixCreateRoom(string $host, string $token, string $name, string $aliasLocalPart, ?string $topic, array $inviteUserIds): ?string
    {
        $body = [
            'name' => $name,
            'room_alias_name' => $aliasLocalPart,
            'preset' => 'private_chat',
            'invite' => array_values($inviteUserIds),
        ];
        if ($topic !== null && $topic !== '') {
            $body['topic'] = $topic;
        }

        $response = Http::withToken($token)->timeout(15)->post("https://{$host}/_matrix/client/v3/createRoom", $body);

        return $response->successful() ? $response->json('room_id') : null;
    }

    /**
     * Full Matrix user IDs currently joined to $roomId. Used to diff
     * against a wanted invite list so re-inviting an already-joined member
     * is skipped rather than re-attempted.
     *
     * @return list<string>
     */
    protected function matrixRoomMembers(string $host, string $token, string $roomId): array
    {
        $response = Http::withToken($token)->timeout(15)
            ->get("https://{$host}/_matrix/client/v3/rooms/".rawurlencode($roomId).'/joined_members');

        if ($response->failed()) {
            return [];
        }

        return array_keys($response->json('joined', []));
    }

    /**
     * Invite a user to a room. Idempotent: a user already in the room (or
     * already invited) is treated as success rather than a failure — the
     * Matrix API 403s M_FORBIDDEN for "already in the room", which isn't a
     * real error for a caller that's just ensuring membership.
     */
    protected function matrixInviteToRoom(string $host, string $token, string $roomId, string $userId): bool
    {
        $response = Http::withToken($token)->timeout(15)->post(
            "https://{$host}/_matrix/client/v3/rooms/".rawurlencode($roomId).'/invite',
            ['user_id' => $userId],
        );

        return $response->successful() || str_contains(strtolower((string) $response->body()), 'already in the room');
    }

    /**
     * Create or update a Matrix user account via the Synapse Admin API v2.
     * Distinguishes create (201) from update (200) via status code, per
     * Synapse's own documented behaviour for this endpoint.
     *
     * @return array{created: bool}|null null on failure
     */
    protected function matrixSetUserAccount(string $host, string $adminToken, string $userId, ?string $password = null, ?string $displayName = null, bool $admin = false): ?array
    {
        $body = ['admin' => $admin];
        if ($password !== null && $password !== '') {
            $body['password'] = $password;
        }
        if ($displayName !== null && $displayName !== '') {
            $body['displayname'] = $displayName;
        }

        $response = Http::withToken($adminToken)->timeout(15)->put(
            "https://{$host}/_synapse/admin/v2/users/".rawurlencode($userId),
            $body,
        );

        if ($response->failed()) {
            return null;
        }

        return ['created' => $response->status() === 201];
    }
}
