<?php

namespace App\Traits;

use App\Http\Integrations\Zitadel\Requests\AddOrgDomainRequest;
use App\Http\Integrations\Zitadel\Requests\AddOrgMemberRequest;
use App\Http\Integrations\Zitadel\Requests\CreateActionRequest;
use App\Http\Integrations\Zitadel\Requests\CreateOidcAppRequest;
use App\Http\Integrations\Zitadel\Requests\CreateOrganizationRequest;
use App\Http\Integrations\Zitadel\Requests\CreateProjectGrantRequest;
use App\Http\Integrations\Zitadel\Requests\CreateProjectRequest;
use App\Http\Integrations\Zitadel\Requests\CreateProjectRoleRequest;
use App\Http\Integrations\Zitadel\Requests\CreateUserGrantRequest;
use App\Http\Integrations\Zitadel\Requests\CreateUserRequest;
use App\Http\Integrations\Zitadel\Requests\DeactivateUserRequest;
use App\Http\Integrations\Zitadel\Requests\DeleteProjectAppRequest;
use App\Http\Integrations\Zitadel\Requests\DeleteUserGrantRequest;
use App\Http\Integrations\Zitadel\Requests\DeleteUserRequest;
use App\Http\Integrations\Zitadel\Requests\GenerateOrgDomainValidationRequest;
use App\Http\Integrations\Zitadel\Requests\GetFlowRequest;
use App\Http\Integrations\Zitadel\Requests\GetProjectRequest;
use App\Http\Integrations\Zitadel\Requests\SearchActionsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchOrganizationsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchOrgDomainsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectAppsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectGrantsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectRolesRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUserGrantsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchUsersRequest;
use App\Http\Integrations\Zitadel\Requests\SetFlowTriggerActionsRequest;
use App\Http\Integrations\Zitadel\Requests\SetUserPasswordRequest;
use App\Http\Integrations\Zitadel\Requests\UpdateActionRequest;
use App\Http\Integrations\Zitadel\Requests\UpdateProjectGrantRequest;
use App\Http\Integrations\Zitadel\Requests\UpdateProjectRequest;
use App\Http\Integrations\Zitadel\Requests\UpdateUserGrantRequest;
use App\Http\Integrations\Zitadel\Requests\ValidateOrgDomainRequest;
use App\Http\Integrations\Zitadel\ZitadelConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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
    protected function zitadelCreateUser(string $host, string $pat, string $email, string $displayName, ?string $password = null, ?string $orgId = null): ?string
    {
        $localPart = explode('@', $email)[0];
        [$givenName, $familyName] = $this->splitDisplayName($displayName, $localPart);

        $response = ZitadelConnector::make($host, $pat)->send(CreateUserRequest::make(
            $email,
            $givenName,
            $familyName,
            $displayName !== '' ? $displayName : $localPart,
            // changeRequired stays false even for a supplied password:
            // forcing a change on first SSO login would immediately break
            // the mail/SSO password parity the caller asked for.
            $password ?? Str::password(32),
            $orgId,
        ));

        if ($response->successful()) {
            return $response->json('userId');
        }

        // Idempotent, matching zitadelAddOrgDomain()'s "AlreadyExists" handling:
        // confirmed live (2026-08-20) that a create call can fail with "User
        // already exists" even on what the caller believed was a first attempt
        // — e.g. a prior run's response never made it back to the CLI (network
        // blip, timeout) even though Zitadel's write went through. Retrying the
        // whole command previously meant a permanent, unrecoverable failure;
        // now it resolves and reuses the existing user instead.
        if (str_contains(strtolower($response->body()), 'already exists')) {
            return $this->zitadelFindUserByEmail($host, $pat, $email);
        }

        return null;
    }

    /** Find an existing Zitadel user by email. Returns their user ID, or null if not found/on failure. */
    protected function zitadelFindUserByEmail(string $host, string $pat, string $email): ?string
    {
        $response = ZitadelConnector::make($host, $pat)->send(SearchUsersRequest::make($email));

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
        return ZitadelConnector::make($host, $pat)
            ->send(SetUserPasswordRequest::make($userId, $password))
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
        $connector = ZitadelConnector::make($host, $pat);

        if ($connector->send(DeleteUserRequest::make($userId))->successful()) {
            return true;
        }

        // Fall back to deactivation — some org policies refuse hard deletes.
        return $connector->send(DeactivateUserRequest::make($userId))->successful();
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
        $connector = ZitadelConnector::make($host, $pat);
        $search = $connector->send(SearchProjectsRequest::make($name));

        if ($search->successful()) {
            $existing = $search->json('result.0.id');
            if ($existing !== null) {
                return $existing;
            }
        }

        $create = $connector->send(CreateProjectRequest::make($name));

        return $create->successful() ? $create->json('id') : null;
    }

    /**
     * Register an OIDC web-app client for a tool under the given project — the
     * counterpart to zitadelDeleteOidcApp(). Returns the new app's
     * {appId, clientId, clientSecret}, or null on failure. Defaults to a
     * confidential client (authMethodType BASIC) matching every server-side
     * tool sso:wire targets (Grafana, Vaultwarden). Pass $publicClient=true
     * for browser SPA tools (oCIS web): those exchange tokens in-page via PKCE
     * and cannot hold a client secret, so the app must be registered with
     * authMethodType NONE and no clientSecret is returned.
     *
     * @return array{appId: string, clientId: string, clientSecret: string}|null
     */
    protected function zitadelCreateOidcApp(string $host, string $pat, string $projectId, string $name, array|string $redirectUri, bool $publicClient = false, array $postLogoutRedirectUris = []): ?array
    {
        $connector = ZitadelConnector::make($host, $pat);

        // 1. Delete existing app by name if present (idempotent overwrite)
        $search = $connector->send(SearchProjectAppsRequest::make($projectId, $name));

        if ($search->successful()) {
            $existingAppId = $search->json('result.0.id');
            if ($existingAppId !== null) {
                $connector->send(DeleteProjectAppRequest::make($projectId, $existingAppId));
            }
        }

        // 2. Create OIDC app with full redirect URIs
        $redirectUris = array_values(array_unique((array) $redirectUri));
        $postLogoutRedirectUris = array_values(array_unique($postLogoutRedirectUris));
        $response = $connector->send(CreateOidcAppRequest::make($projectId, $name, $redirectUris, $publicClient, $postLogoutRedirectUris));

        if ($response->failed()) {
            return null;
        }

        $appId = $response->json('appId');
        $clientId = $response->json('clientId');
        $clientSecret = $response->json('clientSecret') ?? ($publicClient ? '' : null);

        if ($appId === null || $clientId === null || $clientSecret === null) {
            return null;
        }

        return ['appId' => $appId, 'clientId' => $clientId, 'clientSecret' => $clientSecret];
    }

    /** Deregister an OIDC app created by zitadelCreateOidcApp(). */
    protected function zitadelDeleteOidcApp(string $host, string $pat, string $projectId, string $appId): bool
    {
        return ZitadelConnector::make($host, $pat)
            ->send(DeleteProjectAppRequest::make($projectId, $appId))
            ->successful();
    }

    /**
     * Turn on projectRoleAssertion (so granted roles are asserted into the
     * larakube_roles claim by ensureRbacAction()'s Action) AND
     * projectRoleCheck (deny login outright for any user with zero roles on
     * the project) on the RBAC project.
     *
     * projectRoleCheck used to be left off deliberately — the worry was
     * locking out users mid-rollout before grants existed. That worry
     * didn't survive scrutiny: OpenBao keeps its root-token auth and
     * Grafana keeps local password login, both entirely outside Zitadel,
     * and `sso:grant` itself authenticates as the machine PAT rather than
     * an OIDC end-user login — so nothing Zitadel-side can ever actually
     * lock an operator out. What flipping it late DOES cost is a window
     * where zero-role users can reach the app's own gate for no reason
     * (bound_claims/role_attribute_path denies them anyway, just later and
     * with a less clear error) — deny-by-default from the moment a tool is
     * wired is strictly better. GETs current settings first and only
     * changes these two — Zitadel's UpdateProject replaces the whole
     * settings object, so blindly resending hardcoded values would
     * silently clobber hasProjectCheck if an operator had set it manually.
     */
    protected function zitadelEnsureRbacProjectSettings(string $host, string $pat, string $projectId): bool
    {
        $connector = ZitadelConnector::make($host, $pat);
        $get = $connector->send(GetProjectRequest::make($projectId));
        if ($get->failed()) {
            return false;
        }

        $project = $get->json('project', []);
        if (($project['projectRoleAssertion'] ?? false) === true && ($project['projectRoleCheck'] ?? false) === true) {
            return true;
        }

        return $connector->send(UpdateProjectRequest::make(
            $projectId,
            $project['name'],
            true,
            true,
            $project['hasProjectCheck'] ?? false,
        ))->successful();
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
        $connector = ZitadelConnector::make($host, $pat);
        $get = $connector->send(GetProjectRequest::make($projectId));
        if ($get->failed()) {
            return false;
        }

        $project = $get->json('project', []);
        if (($project['projectRoleAssertion'] ?? false) === true) {
            return true;
        }

        return $connector->send(UpdateProjectRequest::make(
            $projectId,
            $project['name'],
            true,
            $project['projectRoleCheck'] ?? false,
            $project['hasProjectCheck'] ?? false,
        ))->successful();
    }

    /**
     * Idempotently create a project role. Zitadel has no "get by key"
     * lookup, so this searches first — POSTing blind and swallowing the
     * AlreadyExists error would also work, but would mask a real failure
     * (wrong project id, revoked PAT) as if the role already existed.
     */
    protected function zitadelEnsureProjectRole(string $host, string $pat, string $projectId, string $roleKey, string $displayName): bool
    {
        $connector = ZitadelConnector::make($host, $pat);
        $search = $connector->send(SearchProjectRolesRequest::make($projectId, $roleKey));

        if ($search->successful() && $search->json('result.0.key') === $roleKey) {
            return true;
        }

        return $connector->send(CreateProjectRoleRequest::make($projectId, $roleKey, $displayName))->successful();
    }

    /**
     * Ensure the org-wide Action that flattens a user's Zitadel project role
     * grants into a single top-level `larakube_roles` claim (array of role
     * keys), and that it's attached to the Complement Token flow's two
     * triggers. bound_claims (OpenBao) and role_attribute_path (Grafana) can
     * only match scalar/list-of-string claims — Zitadel's own roles claim
     * (urn:zitadel:iam:org:project:roles) is a nested object keyed by role,
     * which neither can read. This Action is the flattening step that makes
     * per-tool RBAC possible at all. Verified end-to-end against a live
     * login on 2026-07-30 (larakube_roles: ["openbao-admin"] appeared in the
     * id_token) — see plans/active/openbao-hardening.md.
     *
     * Also emits the SAME array under the standard `groups` claim. Headlamp
     * is the one tool in this system that doesn't do its own OIDC role
     * check — it uses Kubernetes impersonation (Impersonate-User/-Group
     * headers), which means the K8s API server itself, not Headlamp,
     * authorizes each request against whatever ClusterRoleBindings name the
     * impersonated user or group. `groups` is the claim Headlamp reads (it
     * requests the `groups` scope — the DASHBOARD tool's oidcEnv()
     * static block) to build Impersonate-Group. A static ClusterRoleBinding
     * naming Group "dashboard-admin" (dashboard.headlamp.blade.php) is what
     * actually turns that into cluster-admin — this Action only has to get
     * the role key into the token. Root-caused live 2026-08-06: granting
     * dashboard-admin in Zitadel alone did nothing, because no K8s-level
     * binding existed for the impersonated identity at all — see
     * project_larakube... memory / this session for the "Lost connection to
     * the cluster" symptom that led here.
     *
     * Fires for EVERY OIDC client in the org, not just RBAC-gated tools —
     * Zitadel Actions/Flows have no project or app scope. Harmless for tools
     * that don't read either claim, but it does mean this touches every
     * existing tool's login, so the script must degrade to a no-op (not an
     * error) for a user with zero grants.
     *
     * $orgId targets a NON-default org (e.g. a partner org onboarded via
     * sso:org) — Actions/Flows are themselves org-scoped, so a second org
     * needs its own copy of this Action installed; a Project Grant alone
     * does not make role claims appear in that org's own users' tokens.
     * Every call below carries x-zitadel-orgid when set; omitted, the
     * caller's own (master) org is used, unchanged from before.
     */
    protected function zitadelEnsureRbacAction(string $host, string $pat, ?string $orgId = null): bool
    {
        $name = 'flattenLaraKubeRoles';
        $script = <<<'JS'
        function flattenLaraKubeRoles(ctx, api) {
          if (ctx.v1.user.grants == undefined || ctx.v1.user.grants.count == 0) return;
          let roles = [];
          ctx.v1.user.grants.grants.forEach(grant => {
            grant.roles.forEach(role => {
              roles.push(role);
            });
          });
          api.v1.claims.setClaim("larakube_roles", roles);
          api.v1.claims.setClaim("groups", roles);
        }
        JS;

        // Zitadel's _search endpoints want a JSON OBJECT body even with no
        // filters — a bare [] (which is what an empty PHP array always
        // json_encodes to, regardless of client) 400s with "proto: syntax
        // error... unexpected token [". Confirmed live 2026-07-30: this
        // exact bug had been silently breaking this call since it was
        // written (search always failed, fell through to a create that then
        // 409'd against the action created manually during this session's
        // testing — masked entirely because ensureRbacGating() didn't check
        // this method's return value). SearchActionsRequest's own
        // ['queries' => []] body sidesteps it — a real key with an empty
        // array value encodes fine, unlike a bare top-level [].
        $connector = ZitadelConnector::make($host, $pat);

        $search = $connector->send(SearchActionsRequest::make($orgId));
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
            $create = $connector->send(CreateActionRequest::make($name, $script, $orgId));
            if ($create->failed()) {
                return false;
            }
            $actionId = $create->json('id');
        } elseif ($foundScript !== $script) {
            // The Action already exists but predates the groups claim
            // (added 2026-08-06) — push the new script instead of silently
            // leaving the old one in place, same self-heal as
            // zitadelEnsureOcisRolesAction().
            $update = $connector->send(UpdateActionRequest::make($actionId, $name, $script, $orgId));
            if ($update->failed() && ! str_contains($update->body(), 'No Changes')) {
                return false;
            }
        }

        // Complement Token flow (type 2), Pre Userinfo creation (trigger 4)
        // and Pre access token creation (trigger 5) — undocumented in
        // Zitadel's REST reference, confirmed live via the Management API
        // (GET /management/v1/flows/2 returns Action.Flow.Type.CustomiseToken).
        // A resend with the same actionIds 400s with "No Changes" — that's
        // success, not a failure, so it's treated as such below.
        foreach ([4, 5] as $trigger) {
            if (! $this->zitadelAttachActionToFlowTrigger($host, $pat, 2, $trigger, $actionId, $orgId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Attach an action ID to a specific flow trigger type without wiping out
     * other actions already attached to that trigger.
     */
    protected function zitadelAttachActionToFlowTrigger(string $host, string $pat, int $flowType, int $triggerType, string $actionId, ?string $orgId = null): bool
    {
        $connector = ZitadelConnector::make($host, $pat);

        $response = $connector->send(GetFlowRequest::make($flowType, $orgId));
        $existingActionIds = [];

        if ($response->successful()) {
            $triggerActions = $response->json('flow.triggerActions', []);
            foreach ($triggerActions as $ta) {
                if ((int) ($ta['triggerType']['id'] ?? 0) === $triggerType) {
                    foreach ($ta['actions'] ?? [] as $act) {
                        if (! empty($act['id'])) {
                            $existingActionIds[] = (string) $act['id'];
                        }
                    }
                }
            }
        }

        if (in_array($actionId, $existingActionIds, true)) {
            return true;
        }

        $allActionIds = array_values(array_unique(array_merge($existingActionIds, [$actionId])));

        $set = $connector->send(SetFlowTriggerActionsRequest::make($flowType, $triggerType, $allActionIds, $orgId));

        return $set->successful() || str_contains($set->body(), 'No Changes');
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

        $connector = ZitadelConnector::make($host, $pat);

        $search = $connector->send(SearchActionsRequest::make());
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
            $create = $connector->send(CreateActionRequest::make($name, $script));
            if ($create->failed()) {
                return false;
            }
            $actionId = $create->json('id');
        } elseif ($foundScript !== $script) {
            // The Action already exists but its script predates the current
            // schema (e.g. the ocisSpaceAdmin upgrade) — push the new script
            // via PUT /management/v1/actions/{id} with a fieldMask instead of
            // silently leaving the stale claim emitter in place.
            $update = $connector->send(UpdateActionRequest::make($actionId, $name, $script));
            if ($update->failed() && ! str_contains($update->body(), 'No Changes')) {
                return false;
            }
        }

        foreach ([4, 5] as $trigger) {
            if (! $this->zitadelAttachActionToFlowTrigger($host, $pat, 2, $trigger, $actionId)) {
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
        $response = ZitadelConnector::make($host, $pat)->send(SearchProjectRolesRequest::make($projectId));

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
        $response = ZitadelConnector::make($host, $pat)->send(SearchUserGrantsRequest::make($userId, $projectId));

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
        $connector = ZitadelConnector::make($host, $pat);

        if ($existing === null) {
            return $connector->send(CreateUserGrantRequest::make($userId, $projectId, [$roleKey]))->successful();
        }

        if (in_array($roleKey, $existing['roleKeys'], true)) {
            return true;
        }

        return $connector->send(UpdateUserGrantRequest::make(
            $userId,
            $existing['id'],
            array_merge($existing['roleKeys'], [$roleKey]),
        ))->successful();
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
        $connector = ZitadelConnector::make($host, $pat);

        if ($remaining === []) {
            return $connector->send(DeleteUserGrantRequest::make($userId, $existing['id']))->successful();
        }

        return $connector->send(UpdateUserGrantRequest::make($userId, $existing['id'], $remaining))->successful();
    }

    /**
     * Find an org by exact name. Returns its id, or null if not found/on
     * failure. v2 organizations API — shape confirmed live against a real
     * Zitadel v4.16.1 instance (2026-08-19): POST /v2/organizations/_search
     * with a nameQuery filter, same query shape as the long-established v1
     * project/action searches elsewhere in this trait.
     */
    protected function zitadelFindOrgByName(string $host, string $pat, string $name): ?string
    {
        $search = ZitadelConnector::make($host, $pat)->send(SearchOrganizationsRequest::make($name));

        if ($search->failed()) {
            return null;
        }

        return $search->json('result.0.id');
    }

    /**
     * Every org in this Zitadel instance, for an interactive picker —
     * sso:org-grant's --org= counterpart to resolveGatedTool()'s tool
     * picker. Same endpoint as zitadelFindOrgByName(), empty queries to
     * list everything rather than filter by name. No filtering of the
     * operator's own/master org: listing everything and letting the
     * operator pick is safer than guessing which one to hide.
     *
     * @return array<string, string> id => name
     */
    protected function zitadelListOrgs(string $host, string $pat): array
    {
        $search = ZitadelConnector::make($host, $pat)->send(SearchOrganizationsRequest::make());

        if ($search->failed()) {
            return [];
        }

        $orgs = [];
        foreach ($search->json('result', []) as $org) {
            if (isset($org['id'], $org['name'])) {
                $orgs[$org['id']] = $org['name'];
            }
        }

        return $orgs;
    }

    /**
     * Create a new Zitadel organization. Returns its id, or null on failure.
     * v2 API — AddOrganizationRequest.name confirmed live (empty-body probe
     * against sso.luchtech.dev returned "invalid AddOrganizationRequest.Name").
     */
    protected function zitadelCreateOrg(string $host, string $pat, string $name): ?string
    {
        $create = ZitadelConnector::make($host, $pat)->send(CreateOrganizationRequest::make($name));

        if ($create->failed()) {
            return null;
        }

        return $create->json('organizationId');
    }

    /** Find-or-create an org by name — the org-level counterpart to zitadelEnsureProject(). */
    protected function zitadelEnsureOrg(string $host, string $pat, string $name): ?string
    {
        $existing = $this->zitadelFindOrgByName($host, $pat, $name);
        if ($existing !== null) {
            return $existing;
        }

        return $this->zitadelCreateOrg($host, $pat, $name);
    }

    /**
     * Add a custom domain claim to $orgId. Idempotent: "already exists" is
     * treated as success. v1 API — AddOrgDomainRequest.domain confirmed
     * live.
     *
     * Does NOT always leave the domain unverified: confirmed live (2026-08-20,
     * repeatable on a freshly created org) that Zitadel auto-verifies the
     * domain the instant it's added — `isVerified` flips true with
     * `creationDate === changeDate` on the domain object, zero DNS round
     * trip — when the calling PAT already holds instance-level admin rights
     * (our `machine-pat` does). Zitadel reasonably treats a caller that
     * privileged as not needing DNS-01 proof of ownership. Callers MUST call
     * zitadelOrgDomainVerified() after this and skip
     * zitadelGenerateOrgDomainValidation() when it's already true — asking
     * Zitadel to generate a fresh challenge for an already-verified domain
     * fails outright ("Domain is already verified", ORG-HGw21). The
     * generate/validate path stays in place as a fallback for callers or
     * PAT scopes that don't get the auto-verify treatment.
     */
    protected function zitadelAddOrgDomain(string $host, string $pat, string $orgId, string $domain): bool
    {
        $response = ZitadelConnector::make($host, $pat)->send(AddOrgDomainRequest::make($domain, $orgId));

        return $response->successful() || str_contains($response->body(), 'AlreadyExists');
    }

    /**
     * Whether $domain is already verified on $orgId. v1 API —
     * confirmed live via /management/v1/orgs/me/domains/_search
     * (result[].domainName / result[].isVerified). Returns null on a
     * transport/API failure so the caller can distinguish "confirmed not
     * verified" from "couldn't check" rather than treating both the same.
     */
    protected function zitadelOrgDomainVerified(string $host, string $pat, string $orgId, string $domain): ?bool
    {
        $response = ZitadelConnector::make($host, $pat)->send(SearchOrgDomainsRequest::make($orgId));

        if ($response->failed()) {
            return null;
        }

        foreach ((array) $response->json('result', []) as $entry) {
            if (($entry['domainName'] ?? null) === $domain) {
                return (bool) ($entry['isVerified'] ?? false);
            }
        }

        return false;
    }

    /**
     * Generate the DNS ownership challenge for $domain on $orgId. Returns
     * the TXT record's expected value (`token`) and Zitadel's own doc URL
     * for the record name (`_zitadel-challenge.<domain>`), or null on
     * failure. v1 API — DOMAIN_VALIDATION_TYPE_DNS confirmed live (the
     * companion "type must not be UNSPECIFIED" error narrowed this down to
     * exactly that enum member; a follow-up call with it returned "Domain
     * doesn't exist on organization" — i.e. accepted the shape, just needed
     * the domain added first via zitadelAddOrgDomain()).
     *
     * @return array{token: string, url: string}|null
     */
    protected function zitadelGenerateOrgDomainValidation(string $host, string $pat, string $orgId, string $domain): ?array
    {
        $response = ZitadelConnector::make($host, $pat)->send(GenerateOrgDomainValidationRequest::make($domain, $orgId));

        if ($response->failed()) {
            return null;
        }

        $token = $response->json('token');

        return $token === null ? null : ['token' => $token, 'url' => (string) $response->json('url')];
    }

    /**
     * Ask Zitadel to re-check the `_zitadel-challenge.<domain>` TXT record
     * and mark the domain verified. Retries a few times (DNS propagation
     * through Cloudflare's edge can lag a few seconds behind the API write
     * that created the record) before giving up. v1 API — the
     * `.../validation` route (no `_validate` suffix) confirmed live: it
     * resolves to a structured "Not Found" for a domain that was never
     * added, rather than a generic routing 404.
     */
    protected function zitadelValidateOrgDomain(string $host, string $pat, string $orgId, string $domain, int $attempts = 5, int $delaySeconds = 3): bool
    {
        $connector = ZitadelConnector::make($host, $pat);

        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                Sleep::sleep($delaySeconds);
            }

            $response = $connector->send(ValidateOrgDomainRequest::make($domain, $orgId));

            if ($response->successful()) {
                return true;
            }
        }

        return false;
    }

    /** Add a member to an org with the given roles (default: ORG_OWNER, full org admin). v1 API — AddOrgMemberRequest.userId confirmed live. */
    protected function zitadelAddOrgMember(string $host, string $pat, string $orgId, string $userId, array $roles = ['ORG_OWNER']): bool
    {
        $response = ZitadelConnector::make($host, $pat)->send(AddOrgMemberRequest::make($userId, $roles, $orgId));

        return $response->successful() || str_contains($response->body(), 'AlreadyExists');
    }

    /**
     * Find the existing ProjectGrant from $projectId to $grantedOrgId, or
     * null if none exists.
     *
     * @return array{id: string, roleKeys: list<string>}|null
     */
    protected function zitadelFindProjectGrant(string $host, string $pat, string $projectId, string $grantedOrgId): ?array
    {
        $search = ZitadelConnector::make($host, $pat)->send(SearchProjectGrantsRequest::make($projectId));

        if ($search->failed()) {
            return null;
        }

        foreach ($search->json('result', []) as $grant) {
            if (($grant['grantedOrgId'] ?? null) === $grantedOrgId) {
                return ['id' => $grant['grantId'] ?? $grant['id'], 'roleKeys' => $grant['grantedRoleKeys'] ?? $grant['roleKeys'] ?? []];
            }
        }

        return null;
    }

    /**
     * Create or update a ProjectGrant from $projectId to $grantedOrgId —
     * Zitadel's real cross-org sharing primitive: it lets another org's
     * users hold UserGrants against this project without that org owning
     * it. Merges $roleKeys into any existing grant (union, not replace) —
     * same non-destructive idiom as zitadelGrantRole(). Once this exists,
     * the existing, UNMODIFIED zitadelGrantRole()/zitadelFindUserGrant()
     * work against the granted-org's own users — Zitadel resolves the
     * ProjectGrant internally. Returns the grant's id, or null on failure.
     * v1 API — AddProjectGrantRequest.grantedOrgId confirmed live.
     */
    protected function zitadelEnsureProjectGrant(string $host, string $pat, string $projectId, string $grantedOrgId, array $roleKeys): ?string
    {
        $existing = $this->zitadelFindProjectGrant($host, $pat, $projectId, $grantedOrgId);
        $connector = ZitadelConnector::make($host, $pat);

        if ($existing === null) {
            $create = $connector->send(CreateProjectGrantRequest::make($projectId, $grantedOrgId, array_values($roleKeys)));

            return $create->successful() ? ($create->json('grantId') ?? $create->json('id')) : null;
        }

        $merged = array_values(array_unique(array_merge($existing['roleKeys'], $roleKeys)));
        if ($merged === $existing['roleKeys']) {
            return $existing['id'];
        }

        $update = $connector->send(UpdateProjectGrantRequest::make($projectId, $existing['id'], $merged));

        return $update->successful() ? $existing['id'] : null;
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
