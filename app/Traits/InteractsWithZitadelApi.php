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
     * The org-wide Action script that flattens a user's project-role grants
     * into a flat `ocisRoles` claim for oCIS Drive. The claim value is the
     * HIGHEST privilege the user holds — ocisAdmin outranks ocisSpaceAdmin,
     * which outranks the unconditional ocisUser fallback — because oCIS
     * assigns exactly ONE role per user. Exposed as a constant so tests can
     * model a matching/stale remote script without duplicating the JS.
     */
    public const OCIS_ROLES_SCRIPT = <<<'JS'
    function flattenOcisRoles(ctx, api) {
      let roles = ["ocisUser"];
      if (ctx.v1.user.grants != undefined && ctx.v1.user.grants.count > 0) {
        ctx.v1.user.grants.grants.forEach(grant => {
          grant.roles.forEach(role => {
            if (role === "ocisAdmin") {
              roles = ["ocisAdmin"];
            } else if (role === "ocisSpaceAdmin" && roles[0] !== "ocisAdmin") {
              roles = ["ocisSpaceAdmin"];
            }
          });
        });
      }
      api.v1.claims.setClaim("ocisRoles", roles);
    }
    JS;

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
     * Enable projectRoleAssertion on an open-to-org tool's project so the
     * org-wide flattenOcisRoles Action can see user grants in its context.
     *
     * Zitadel only populates ctx.v1.user.grants for projects that are part
     * of the token/userinfo "role audience" — which is built from the
     * project's project_role_assertion flag when the client's scope does not
     * explicitly request project roles (oCIS web requests plain
     * openid/profile/email). Without the flag the Action's grants list
     * resolves empty at runtime, the ocisUser fallback fires, and every
     * grantee is silently demoted to the plain "user" role — the live
     * "no + New Space button" bug on Drive (2026-08-02).
     *
     * Unlike zitadelEnsureRbacProjectSettings() this deliberately does NOT
     * enable projectRoleCheck: the Action's ocisUser fallback is what keeps
     * zero-role org members able to log in. GETs the current settings first
     * and only flips the one flag — Zitadel's UpdateProject replaces the
     * whole settings object, so blindly resending hardcoded values would
     * silently clobber projectRoleCheck/hasProjectCheck if an operator had
     * set them manually.
     */
    protected function zitadelEnsureSsoAdminProjectSettings(string $host, string $pat, string $projectId): bool
    {
        $get = Http::withToken($pat)->timeout(15)->get("https://{$host}/management/v1/projects/{$projectId}");
        if ($get->failed()) {
            return false;
        }

        $project = $get->json('project', []);
        if (($project['projectRoleAssertion'] ?? false) === true) {
            return true;
        }

        return Http::withToken($pat)->timeout(15)->put("https://{$host}/management/v1/projects/{$projectId}", [
            'name' => $project['name'],
            'projectRoleAssertion' => true,
            'projectRoleCheck' => $project['projectRoleCheck'] ?? false,
            'hasProjectCheck' => $project['hasProjectCheck'] ?? false,
        ])->successful();
    }

    /**
     * Idempotently create a project role. Zitadel has no "get by key"
     * lookup, so this searches first — POSTing blind and swallowing the
     * AlreadyExists error would also work, but would mask a real failure
     * (wrong project id, revoked PAT) as if the role already existed.
     */
    protected function zitadelEnsureProjectRole(string $host, string $pat, string $projectId, string $roleKey, string $displayName): bool
    {
        $search = Http::withToken($pat)->timeout(15)->post(
            "https://{$host}/management/v1/projects/{$projectId}/roles/_search",
            ['queries' => [['keyQuery' => ['key' => $roleKey]]]],
        );

        if ($search->successful() && $search->json('result.0.key') === $roleKey) {
            return true;
        }

        return Http::withToken($pat)->timeout(15)->post(
            "https://{$host}/management/v1/projects/{$projectId}/roles",
            ['roleKey' => $roleKey, 'displayName' => $displayName],
        )->successful();
    }

    /**
     * Ensure the org-wide Action that flattens a user's project-role grants
     * into a flat `ocisRoles` claim for oCIS Drive. oCIS's
     * PROXY_ROLE_ASSIGNMENT_DRIVER=oidc re-asserts a user's role from this
     * claim on EVERY login, and — unlike the larakube_roles Action — DENIES
     * a login whose token carries no matching claim at all. Zitadel's native
     * roles claim is a nested object, so driver=oidc needs this Action, and
     * the Action must be UNCONDITIONALLY SAFE: it always emits ["ocisUser"]
     * as a fallback and only upgrades to ["ocisAdmin"] or ["ocisSpaceAdmin"]
     * when the user holds the corresponding drive role on the shared project.
     * That no-match-guarantee is what lets sso:wire ship driver=oidc to every
     * org member without locking anyone out (see the ssoAdminRoles() docblock
     * on the ClusterTool enum).
     *
     * A separate Action/claim from flattenLaraKubeRoles on purpose — the two
     * serve different contracts (REST permissioning vs. oCIS role mapping),
     * and tools that read `larakube_roles` must not start seeing oCIS roles.
     * Both are org-wide (Actions have no project/app scope), so this claim
     * rides along on every client's token; only oCIS reads it.
     *
     * Same search/create/flow-attach mechanics as zitadelEnsureRbacAction(),
     * plus an idempotent script refresh: re-running sso:wire after a role
     * (e.g. ocisSpaceAdmin) was added must push the new script onto an
     * already-existing Action instead of silently skipping it (AGENTS.md
     * Idempotency standard).
     */
    protected function zitadelEnsureOcisRolesAction(string $host, string $pat): bool
    {
        $name = 'flattenOcisRoles';
        $script = self::OCIS_ROLES_SCRIPT;

        $search = Http::withToken($pat)->timeout(15)->post("https://{$host}/management/v1/actions/_search", ['queries' => []]);
        $actionId = null;
        $foundScript = null;
        if ($search->successful()) {
            foreach ($search->json('result', []) as $action) {
                if (($action['name'] ?? null) === $name) {
                    $actionId = $action['id'];
                    $foundScript = $action['script'] ?? null;
                    break;
                }
            }
        }

        if ($actionId === null) {
            $create = Http::withToken($pat)->timeout(15)->post("https://{$host}/management/v1/actions", [
                'name' => $name,
                'script' => $script,
                'timeout' => '10s',
            ]);
            if ($create->failed()) {
                return false;
            }
            $actionId = $create->json('id');
        } elseif ($foundScript !== $script) {
            // The Action already exists but its script predates the current
            // schema (e.g. the ocisSpaceAdmin upgrade) — push the new script
            // via PUT /management/v1/actions/{id} with a fieldMask instead of
            // silently leaving the stale claim emitter in place.
            $update = Http::withToken($pat)->timeout(15)->put(
                "https://{$host}/management/v1/actions/{$actionId}",
                ['name' => $name, 'script' => $script, 'fieldMask' => ['paths' => ['name', 'script']]],
            );
            if ($update->failed() && ! str_contains($update->body(), 'No Changes')) {
                return false;
            }
        }

        foreach ([4, 5] as $trigger) {
            $set = Http::withToken($pat)->timeout(15)->post(
                "https://{$host}/management/v1/flows/2/trigger/{$trigger}",
                ['actionIds' => [$actionId]],
            );
            if ($set->failed() && ! str_contains($set->body(), 'No Changes')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The role keys actually defined on a project right now — used to tell
     * "this tool's enum declares rbacRoles()" apart from "this tool has
     * actually been sso:wire'd", since ensureRbacGating() is what creates
     * those roles in Zitadel and only runs inside sso:wire. A tool merely
     * being deployed on the cluster doesn't mean it's been wired.
     *
     * @return list<string>
     */
    protected function zitadelListProjectRoleKeys(string $host, string $pat, string $projectId): array
    {
        // See the identical fix + explanation in zitadelEnsureRbacAction():
        // Zitadel 400s a bare [] body on _search endpoints — needs {}.
        $response = Http::withToken($pat)->timeout(15)->post(
            "https://{$host}/management/v1/projects/{$projectId}/roles/_search",
            ['queries' => []],
        );

        if ($response->failed()) {
            return [];
        }

        return array_column($response->json('result', []), 'key');
    }

    /**
     * Find the existing UserGrant on a project for a user, or null if they
     * hold none there. A user can only ever have ONE grant per project in
     * Zitadel (it holds a list of roleKeys, not one grant per role) —
     * granting a second role means updating this grant's roleKeys, not
     * creating a new one, which is why zitadelGrantRole()/zitadelRevokeRole()
     * both start here rather than blindly POSTing.
     *
     * @return array{id: string, roleKeys: list<string>}|null
     */
    protected function zitadelFindUserGrant(string $host, string $pat, string $userId, string $projectId): ?array
    {
        $response = Http::withToken($pat)->timeout(15)->post(
            "https://{$host}/management/v1/users/grants/_search",
            ['queries' => [
                ['userIdQuery' => ['userId' => $userId]],
                ['projectIdQuery' => ['projectId' => $projectId]],
            ]],
        );

        if ($response->failed()) {
            return null;
        }

        $grant = $response->json('result.0');

        return $grant === null ? null : ['id' => $grant['id'], 'roleKeys' => $grant['roleKeys'] ?? []];
    }

    /**
     * Grant a role to a user on a project, merging into their existing
     * grant there (if any) rather than clobbering other roles they already
     * hold. Idempotent: already holding the role is a no-op success.
     */
    protected function zitadelGrantRole(string $host, string $pat, string $userId, string $projectId, string $roleKey): bool
    {
        $existing = $this->zitadelFindUserGrant($host, $pat, $userId, $projectId);

        if ($existing === null) {
            return Http::withToken($pat)->timeout(15)->post(
                "https://{$host}/management/v1/users/{$userId}/grants",
                ['projectId' => $projectId, 'roleKeys' => [$roleKey]],
            )->successful();
        }

        if (in_array($roleKey, $existing['roleKeys'], true)) {
            return true;
        }

        return Http::withToken($pat)->timeout(15)->put(
            "https://{$host}/management/v1/users/{$userId}/grants/{$existing['id']}",
            ['roleKeys' => array_merge($existing['roleKeys'], [$roleKey])],
        )->successful();
    }

    /**
     * Revoke a role from a user's grant on a project. Deletes the grant
     * entirely once its last role is removed — an empty-roleKeys grant
     * doesn't mean anything here. Idempotent: not holding the role (or
     * holding no grant at all) is a no-op success.
     */
    protected function zitadelRevokeRole(string $host, string $pat, string $userId, string $projectId, string $roleKey): bool
    {
        $existing = $this->zitadelFindUserGrant($host, $pat, $userId, $projectId);
        if ($existing === null || ! in_array($roleKey, $existing['roleKeys'], true)) {
            return true;
        }

        $remaining = array_values(array_diff($existing['roleKeys'], [$roleKey]));

        if ($remaining === []) {
            return Http::withToken($pat)->timeout(15)
                ->delete("https://{$host}/management/v1/users/{$userId}/grants/{$existing['id']}")
                ->successful();
        }

        return Http::withToken($pat)->timeout(15)->put(
            "https://{$host}/management/v1/users/{$userId}/grants/{$existing['id']}",
            ['roleKeys' => $remaining],
        )->successful();
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
