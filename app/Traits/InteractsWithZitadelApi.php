<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Zitadel's v2 User Service API, reached directly over HTTPS via its public
 * ingress with the bootstrapped machine PAT — same shape as
 * VpnInitCommand::bootstrapVpnAuth()'s calls to NetBird's /api/setup, not a
 * kubectl-exec proxy like Stalwart's JMAP (Zitadel's API is meant to be
 * reached externally). Method shapes verified against Zitadel's own API
 * reference docs (user_service_v2), not inferred — but without a live
 * instance to round-trip against this session (unlike Stalwart's JMAP,
 * which WAS verified live), treat this as one notch less certain and expect
 * to fix small field-name mismatches once this runs against reality.
 */
trait InteractsWithZitadelApi
{
    /**
     * Create a human user with the given email, matching the account
     * mail:create just made in Stalwart. Returns the new user's ID, or null
     * on failure (network error, or Zitadel rejected the request).
     *
     * @param  string|null  $password  Initial Zitadel password. Pass the mailbox
     *                                 password so the same credential logs into both mail and SSO — the caller
     *                                 only has the plaintext at account-creation time (Stalwart stores a hash),
     *                                 so mail:create supplies it and mail:sync-sso (importing existing accounts)
     *                                 cannot. When null, a random unusable password is set: the record still
     *                                 needs some initial credential, but the account is SSO-only.
     */
    protected function zitadelCreateUser(string $host, string $pat, string $email, string $displayName, ?string $password = null): ?string
    {
        $localPart = explode('@', $email)[0];
        [$givenName, $familyName] = $this->splitDisplayName($displayName, $localPart);

        $response = Http::withToken($pat)
            ->timeout(15)
            ->post("https://{$host}/v2/users/human", [
                'username' => $email,
                'profile' => [
                    'givenName' => $givenName,
                    'familyName' => $familyName,
                    'displayName' => $displayName !== '' ? $displayName : $localPart,
                ],
                'email' => [
                    'email' => $email,
                    'isVerified' => true,
                ],
                'password' => [
                    // changeRequired stays false even for a supplied password:
                    // forcing a change on first SSO login would immediately break
                    // the mail/SSO password parity the caller asked for.
                    'password' => $password ?? Str::password(32),
                    'changeRequired' => false,
                ],
            ]);

        if ($response->failed()) {
            return null;
        }

        return $response->json('userId');
    }

    /** Find an existing Zitadel user by email. Returns their user ID, or null if not found/on failure. */
    protected function zitadelFindUserByEmail(string $host, string $pat, string $email): ?string
    {
        $response = Http::withToken($pat)
            ->timeout(15)
            ->post("https://{$host}/v2/users", [
                'queries' => [
                    ['emailQuery' => ['emailAddress' => $email, 'method' => 'TEXT_QUERY_METHOD_EQUALS']],
                ],
            ]);

        if ($response->failed()) {
            return null;
        }

        return $response->json('result.0.userId');
    }

    /**
     * Set a user's Zitadel password directly (admin path, via the machine PAT —
     * no current-password or verification code needed). Used to keep the SSO
     * password in step with a Stalwart mailbox password reset. changeRequired
     * stays false to preserve the mail/SSO password parity, same as
     * zitadelCreateUser().
     */
    protected function zitadelSetPassword(string $host, string $pat, string $userId, string $password): bool
    {
        return Http::withToken($pat)
            ->timeout(15)
            ->post("https://{$host}/v2/users/{$userId}/password", [
                'newPassword' => [
                    'password' => $password,
                    'changeRequired' => false,
                ],
            ])
            ->successful();
    }

    /**
     * Point Zitadel's own outbound email (verification, password-reset, OTP) at
     * an SMTP server via the Admin API — the runtime path, since SMTP is a
     * DEFAULTINSTANCE setting that env vars can only seed on a fresh instance.
     * Two steps: add the SMTP email provider, then activate it (Zitadel v4's
     * multi-provider model keeps a new provider inactive until activated).
     * `host` must include the port (e.g. mail.example.com:465). Returns true
     * only if both add + activate succeed; NON-FATAL for the caller — a failure
     * just means finish it in the console (Settings → SMTP), which has a test
     * button. API shape verified against Zitadel's docs, not a live instance.
     */
    protected function zitadelConfigureSmtp(string $host, string $pat, string $smtpHost, string $user, string $password, string $senderAddress, string $senderName): bool
    {
        try {
            $payload = [
                'host' => $smtpHost,
                'tls' => true,
                'user' => $user,
                'password' => $password,
                'senderAddress' => $senderAddress,
                'senderName' => $senderName,
            ];

            // 1. Check if providers already exist
            $search = Http::withToken($pat)->timeout(15)->post("https://{$host}/admin/v1/email/_search", (object) []);
            $providerId = null;
            if ($search->successful()) {
                $results = $search->json('result', []);
                if (! empty($results)) {
                    $providerId = $results[0]['id'] ?? null;
                    // Prune any extra duplicate providers
                    for ($i = 1; $i < count($results); $i++) {
                        $staleId = $results[$i]['id'] ?? null;
                        if ($staleId !== null) {
                            Http::withToken($pat)->timeout(10)->delete("https://{$host}/admin/v1/email/{$staleId}");
                        }
                    }
                }
            }

            // 2. Update existing provider or create a new one
            if ($providerId !== null) {
                $update = Http::withToken($pat)->timeout(15)->put("https://{$host}/admin/v1/email/smtp/{$providerId}", $payload);
                if (! $update->successful()) {
                    return false;
                }
            } else {
                $add = Http::withToken($pat)->timeout(15)->post("https://{$host}/admin/v1/email/smtp", $payload);
                if (! $add->successful()) {
                    return false;
                }
                $providerId = $add->json('id');
            }

            // 3. ALWAYS activate the provider to guarantee the active state (green checkmark)
            if ($providerId !== null) {
                $activate = Http::withToken($pat)->timeout(15)
                    ->post("https://{$host}/admin/v1/email/{$providerId}/_activate", (object) []);

                return $activate->successful()
                    || $activate->status() === 400
                    || str_contains((string) $activate->body(), 'AlreadyActive');
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /** Delete (or deactivate, if delete is refused) a Zitadel user by ID. */
    protected function zitadelDeleteUser(string $host, string $pat, string $userId): bool
    {
        $response = Http::withToken($pat)->timeout(15)->delete("https://{$host}/v2/users/{$userId}");

        if ($response->successful()) {
            return true;
        }

        // Fall back to deactivation — some org policies refuse hard deletes.
        return Http::withToken($pat)->timeout(15)->post("https://{$host}/v2/users/{$userId}/deactivate")->successful();
    }

    /**
     * Find (or create) the single Zitadel Project every LaraKube-registered
     * OIDC client lives under — one project keeps sso:wire's app list tidy
     * instead of scattering apps across the default org project. Management
     * API v1 (not v2): Zitadel's v2 app/project APIs weren't stable enough
     * to build against confidently at the time this was written, whereas v1
     * project/app endpoints are long-documented and unlikely to move.
     */
    protected function zitadelEnsureProject(string $host, string $pat, string $name = 'LaraKube'): ?string
    {
        $search = Http::withToken($pat)->timeout(15)->post("https://{$host}/management/v1/projects/_search", [
            'queries' => [['nameQuery' => ['name' => $name, 'method' => 'TEXT_QUERY_METHOD_EQUALS']]],
        ]);

        if ($search->successful()) {
            $existing = $search->json('result.0.id');
            if ($existing !== null) {
                return $existing;
            }
        }

        $create = Http::withToken($pat)->timeout(15)->post("https://{$host}/management/v1/projects", [
            'name' => $name,
        ]);

        return $create->successful() ? $create->json('id') : null;
    }

    /**
     * Register a confidential OIDC web-app client for a tool under the given
     * project — the counterpart to zitadelDeleteOidcApp(). Returns the new
     * app's {appId, clientId, clientSecret}, or null on failure. authMethodType
     * BASIC + a single authorization-code redirect URI matches every tool
     * sso:wire targets (Grafana, Vaultwarden) — both are confidential
     * server-side clients, not SPAs.
     *
     * @return array{appId: string, clientId: string, clientSecret: string}|null
     */
    protected function zitadelCreateOidcApp(string $host, string $pat, string $projectId, string $name, array|string $redirectUri): ?array
    {
        // 1. Delete existing app by name if present (idempotent overwrite)
        $search = Http::withToken($pat)->timeout(15)->post(
            "https://{$host}/management/v1/projects/{$projectId}/apps/_search",
            [
                'queries' => [['nameQuery' => ['name' => $name, 'method' => 'TEXT_QUERY_METHOD_EQUALS']]],
            ],
        );

        if ($search->successful()) {
            $existingAppId = $search->json('result.0.id');
            if ($existingAppId !== null) {
                Http::withToken($pat)->timeout(15)->delete("https://{$host}/management/v1/projects/{$projectId}/apps/{$existingAppId}");
            }
        }

        // 2. Create OIDC app with full redirect URIs
        $redirectUris = array_values(array_unique((array) $redirectUri));
        $response = Http::withToken($pat)->timeout(15)->post(
            "https://{$host}/management/v1/projects/{$projectId}/apps/oidc",
            [
                'name' => $name,
                'redirectUris' => $redirectUris,
                'responseTypes' => ['OIDC_RESPONSE_TYPE_CODE'],
                'grantTypes' => ['OIDC_GRANT_TYPE_AUTHORIZATION_CODE', 'OIDC_GRANT_TYPE_REFRESH_TOKEN'],
                'appType' => 'OIDC_APP_TYPE_WEB',
                'authMethodType' => 'OIDC_AUTH_METHOD_TYPE_BASIC',
                'accessTokenType' => 'OIDC_TOKEN_TYPE_BEARER',
            ],
        );

        if ($response->failed()) {
            return null;
        }

        $appId = $response->json('appId');
        $clientId = $response->json('clientId');
        $clientSecret = $response->json('clientSecret');

        if ($appId === null || $clientId === null || $clientSecret === null) {
            return null;
        }

        return ['appId' => $appId, 'clientId' => $clientId, 'clientSecret' => $clientSecret];
    }

    /** Deregister an OIDC app created by zitadelCreateOidcApp(). */
    protected function zitadelDeleteOidcApp(string $host, string $pat, string $projectId, string $appId): bool
    {
        return Http::withToken($pat)->timeout(15)
            ->delete("https://{$host}/management/v1/projects/{$projectId}/apps/{$appId}")
            ->successful();
    }

    /**
     * Zitadel's profile requires separate given/family names; a mailbox only
     * has an email + one free-text display name. Best-effort split on the
     * first space, falling back to the email's local part when there's
     * nothing to split.
     *
     * @return array{0: string, 1: string}
     */
    protected function splitDisplayName(string $displayName, string $fallback): array
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            return [$fallback, $fallback];
        }

        $parts = explode(' ', $displayName, 2);

        return [$parts[0], $parts[1] ?? $parts[0]];
    }
}
