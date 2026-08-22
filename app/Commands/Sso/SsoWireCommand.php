<?php

namespace App\Commands\Sso;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SecretsBackend;
use App\Http\Integrations\Zitadel\Requests\GetProjectAppRequest;
use App\Http\Integrations\Zitadel\ZitadelConnector;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\ReconcilesPenpotFlags;
use App\Traits\RefusesUnshippedTools;
use App\Traits\ResolvesToolEngine;
use App\Traits\ResolvesToolHost;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class SsoWireCommand extends Command
{
    use DeploysClusterTool, InteractsWithChat, InteractsWithClusterContext, InteractsWithSso, InteractsWithZitadelApi, LaraKubeOutput, ReconcilesPenpotFlags, RefusesUnshippedTools, ResolvesToolEngine, ResolvesToolHost, SyncsClusterSecrets;

    protected $signature = 'sso:wire
        {environment=local : Environment whose deployment to wire}
        {--tool= : The tool to wire to Zitadel}
        {--engine= : Specific engine to target explicitly, skipping auto-detection (e.g. --engine=pocketbase)}
        {--domain= : The instance to target (e.g. --domain=blog.example.com). Omit for the default instance}
        {--context= : Target a specific kube-context}
        {--project= : Zitadel project name to register the OIDC app under (default: LaraKube Shared Tools)}
        {--admin-email= : Email of the user to grant the tool\'s admin role to (tools with ssoAdminRoles(), e.g. drive)}
        {--sso-only : Enforce SSO-only login and disable local password authentication}';

    protected $description = 'Register a tool as an OIDC client in Zitadel and wire its login to SSO';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->ssoKubectl($context);
        $ssoNs = $this->ssoNamespace();

        $tool = $this->resolveTool($kubectl);
        if ($tool === null) {
            return 1;
        }

        if (! $tool->hasSsoWire()) {
            $this->laraKubeError("'{$tool->value}' can't be wired to SSO.");

            return 1;
        }

        $ssoHost = $this->resolveSsoHostReadOnly($env, $config, $kubectl);
        // --domain= IS the target instance's host (ADR-0012's "host is the
        // only identity") — it overrides the tool's own resolved default
        // host rather than being a separate operator-invented name, so this
        // can target any registered instance, not just the main one.
        $domainOption = (string) ($this->option('domain') ?: '');
        $toolHost = $domainOption !== ''
            ? $this->sanitizeDomainInput($domainOption)
            : $this->targetHost($tool, $env, $config, $kubectl);

        if ($ssoHost === null || $toolHost === null) {
            $missing = $ssoHost === null && $toolHost === null
                ? "{$tool->getLabel()} or Zitadel"
                : ($ssoHost === null ? 'Zitadel' : $tool->getLabel());
            $this->laraKubeError("No host is configured for {$missing} in '{$env}' — run its :init command first.");

            return 1;
        }

        $instance = $this->resolveInstanceForDomain($kubectl, $tool, (string) ($toolHost ?? ''));
        $engine = $this->resolveInstanceEngine($kubectl, $tool, $instance, $this->option('engine'));
        $schema = $tool->oidcEnv($engine, $instance);
        if ($schema === null) {
            return 1;
        }

        if (! $this->isSsoInstalled($kubectl, $ssoNs)) {
            $this->laraKubeError('Zitadel is not installed. Run `larakube sso:init` first.');

            return 1;
        }

        if (! $this->deploymentExists($kubectl, $schema['namespace'], $schema['deployment'])) {
            $label = $engine ? "{$tool->productName($engine)} ({$engine})" : $tool->getLabel();
            $this->laraKubeError("{$label} is not installed.");

            return 1;
        }

        $pat = $this->readSsoSecret($kubectl, $ssoNs, 'machine-pat');
        if ($pat === null) {
            $this->laraKubeError('Could not reach Zitadel\'s automation credentials — re-run `larakube sso:init` to recapture them.');

            return 1;
        }

        return $this->wire($tool, $schema, $kubectl, $ssoNs, $ssoHost, $toolHost, $pat, $env, $engine, $instance);
    }

    protected function wire(ClusterTool $tool, array $schema, string $kubectl, string $ssoNs, string $ssoHost, string $toolHost, string $pat, string $env, ?string $engine = null, ?string $instance = null): int
    {
        // ForwardAuth tools have no native OIDC to configure — gating happens at
        // the ingress, so they never get a per-tool Zitadel app or env vars.
        if ($tool->usesForwardAuth()) {
            return $this->wireForwardAuth($tool, $schema, $kubectl, $ssoNs, $ssoHost, $toolHost, $pat, $env, $instance);
        }

        $appSecret = "sso-app-{$tool->value}";
        $clientId = $this->readClusterSecretKey($kubectl, $ssoNs, $appSecret, 'client-id');
        $clientSecret = $this->readClusterSecretKey($kubectl, $ssoNs, $appSecret, 'client-secret');

        $defaultProject = $tool->requiresRbacGating() ? $tool->rbacProjectName($instance) : 'LaraKube Shared Tools';
        $projectName = (string) ($this->option('project') ?: $defaultProject);
        $projectId = $this->zitadelEnsureProject($ssoHost, $pat, $projectName);

        if ($tool->requiresRbacGating()) {
            if ($projectId === null || ! $this->ensureRbacGating($ssoHost, $pat, $projectId, $tool, $instance !== null ? $toolHost : null)) {
                $this->laraKubeError(
                    "Could not set up role-gated access for {$tool->getLabel()} — the claim-flattening Action, ".
                    'project roles, or role assertion failed to apply. Wiring bound_claims/role_attribute_path '.
                    'against unconfirmed infrastructure would risk denying every login, so this stops here.',
                );

                return 1;
            }
        }

        // Open-to-org tools with ssoAdminRoles() (e.g. drive's ocisAdmin)
        // ship PROXY_ROLE_ASSIGNMENT_DRIVER=oidc, which re-asserts the role
        // from the `ocisRoles` claim on every login and DENIES a token with
        // no such claim. Installing the claim-flattening Action + the admin
        // role is therefore NOT optional — it's the safety precondition that
        // makes driver=oidc safe, exactly like ensureRbacGating() for
        // RBAC-gated tools. Without it, sso:wire would turn off the only
        // thing that keeps open-to-org logins working.
        if ($tool->ssoAdminRoles() !== []) {
            if ($projectId === null || ! $this->ensureSsoAdminGating($ssoHost, $pat, $projectId, $tool)) {
                $this->laraKubeError(
                    "Could not set up admin-role claims for {$tool->getLabel()} — the claim-flattening Action or ".
                    'project role failed to apply. PROXY_ROLE_ASSIGNMENT_DRIVER=oidc depends on the ocisRoles '.
                    'claim, so this stops here rather than deny every login.',
                );

                return 1;
            }

            $adminEmail = trim((string) ($this->option('admin-email') ?: ''));
            if ($adminEmail !== '' && ! $this->ensureSsoAdminGrant($ssoHost, $pat, $projectId, $tool, $adminEmail)) {
                return 1;
            }
        }

        // Zitadel's app endpoint keys on the APP id, not the client id — they're
        // different values. Looking it up by client id always 404s, which made
        // the reuse branch below dead code: every re-run silently re-registered
        // and rotated the client secret, despite the "pass --remove to rotate"
        // message promising the opposite.
        $appId = $this->readClusterSecretKey($kubectl, $ssoNs, $appSecret, 'app-id');

        $appExistsInZitadel = false;
        $registeredRedirectUris = null;
        $registeredPostLogoutRedirectUris = null;
        if ($appId !== null && $appId !== '' && $projectId !== null && $projectId !== '') {
            $checkApp = ZitadelConnector::make($ssoHost, $pat)->send(GetProjectAppRequest::make($projectId, $appId));
            if ($checkApp->successful()) {
                $appExistsInZitadel = true;
                $registeredRedirectUris = $checkApp->json('app.oidcConfig.redirectUris');
                $registeredPostLogoutRedirectUris = $checkApp->json('app.oidcConfig.postLogoutRedirectUris');
            }
        }

        // A registered app is only reusable if it still matches THIS tool's
        // current schema. Existence alone was the reuse gate before, which
        // silently kept stale Zitadel registrations — the live drive bug:
        // its app was confidential with the tool root as the only redirect URI
        // while the pod already ran the corrected env. Reusing it 400'd every
        // /oidc-callback.html authorize request. Redirect URIs are the one
        // signal the app GET exposes (authMethodType is not returned), and
        // they are exactly what changed for drive, so compare them.
        $desiredRedirectUris = $tool->oidcRedirectUris($toolHost, [], $engine);
        $redirectUrisMatch = is_array($registeredRedirectUris)
            && count($desiredRedirectUris) === count($registeredRedirectUris)
            && array_diff($desiredRedirectUris, $registeredRedirectUris) === [];

        // Same staleness gate for post_logout_redirect_uri. oCIS web sends its
        // origin root to end_session; an app registered without
        // postLogoutRedirectUris 400s every logout ("post_logout_redirect_uri
        // invalid" — live 2026-08-01). A registered app is only reusable if its
        // post-logout set still matches the tool's current schema, or re-wiring
        // would silently leave logout broken. A missing field counts as an
        // empty set, so tools that don't use RP-initiated logout still reuse.
        $desiredPostLogoutRedirectUris = $tool->oidcPostLogoutRedirectUris($toolHost);
        $registeredPostLogout = is_array($registeredPostLogoutRedirectUris) ? $registeredPostLogoutRedirectUris : [];
        $postLogoutRedirectUrisMatch = count($desiredPostLogoutRedirectUris) === count($registeredPostLogout)
            && array_diff($desiredPostLogoutRedirectUris, $registeredPostLogout) === [];

        // Public SPA clients carry no client secret, so a missing secret is not
        // a "needs registration" signal for them — only confidential clients
        // require one.
        $publicClient = (bool) ($schema['public_client'] ?? false);
        $missingCredentials = $clientId === null || (! $publicClient && $clientSecret === null);
        if ($missingCredentials || ! $appExistsInZitadel || ! $redirectUrisMatch || ! $postLogoutRedirectUrisMatch) {
            $redirectUris = $desiredRedirectUris;

            $registered = null;
            $this->withSpin("Registering {$tool->getLabel()} as an OIDC client in Zitadel...", function () use (&$registered, $ssoHost, $pat, $projectName, $tool, $redirectUris, $publicClient, $desiredPostLogoutRedirectUris, $engine): void {
                $projectId = $this->zitadelEnsureProject($ssoHost, $pat, $projectName);
                if ($projectId === null) {
                    return;
                }

                $app = $this->zitadelCreateOidcApp($ssoHost, $pat, $projectId, $tool->productName($engine), $redirectUris, $publicClient, $desiredPostLogoutRedirectUris);
                if ($app === null) {
                    return;
                }

                $registered = array_merge($app, ['projectId' => $projectId]);
            });

            if ($registered === null) {
                $this->laraKubeError("Could not register {$tool->getLabel()} in Zitadel — check the automation credentials and Zitadel's own logs.");

                return 1;
            }

            Process::run(
                "{$kubectl} create secret generic {$appSecret} -n {$ssoNs} "
                .'--from-literal=project-id='.escapeshellarg($registered['projectId']).' '
                .'--from-literal=app-id='.escapeshellarg($registered['appId']).' '
                .'--from-literal=client-id='.escapeshellarg($registered['clientId']).' '
                .'--from-literal=client-secret='.escapeshellarg($registered['clientSecret']).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            // A public SPA client has no secret to vault.
            if (! $publicClient && $this->secretsBackendAvailable($kubectl)) {
                $clusterEnv = $env === 'local' ? 'dev' : $env;
                $prefix = strtoupper($tool->name);
                $this->pushClusterSecret($kubectl, "{$prefix}_OIDC_CLIENT_ID", $registered['clientId'], $clusterEnv);
                $this->pushClusterSecret($kubectl, "{$prefix}_OIDC_CLIENT_SECRET", $registered['clientSecret'], $clusterEnv);
            }

            $clientId = $registered['clientId'];
            $clientSecret = $registered['clientSecret'];
        } else {
            $this->laraKubeInfo("Reusing {$tool->getLabel()}'s existing Zitadel OIDC client (run `sso:unwire` then re-wire to rotate it).");
        }

        $logical = [
            'client_id' => $clientId,
            // Public SPA clients (oCIS web) exchange tokens with PKCE in the
            // browser and hold no client secret. Writing an empty value makes
            // applyToolEnv overwrite any stale secret left over from an earlier
            // confidential registration instead of leaving it dangling.
            'client_secret' => ($schema['public_client'] ?? false) ? '' : $clientSecret,
            'auth_url' => "https://{$ssoHost}/oauth/v2/authorize",
            'token_url' => "https://{$ssoHost}/oauth/v2/token",
            'userinfo_url' => "https://{$ssoHost}/oidc/v1/userinfo",
            'issuer' => "https://{$ssoHost}",
            // Full discovery URL for clients that fetch it directly (e.g.
            // Documenso/NextAuth's `wellKnown`), vs. issuer-base clients.
            'well_known' => "https://{$ssoHost}/.well-known/openid-configuration",
            // Full absolute callback URL for tools that require it as an env var
            // (e.g. Teable's BACKEND_OIDC_CALLBACK_URL).
            'callback_url' => "https://{$toolHost}{$schema['redirect_path']}",
        ];

        // Synapse is configured via homeserver.yaml (a Secret), not env vars.
        // Skip applyToolEnv and go straight to wireSynapseOidc.
        // OpenBao is configured via its CLI inside the pod (bao auth enable oidc).
        if ($schema['deployment'] === 'chat-synapse') {
            $ok = $this->wireSynapseOidc($kubectl, $schema['namespace'], $ssoHost, $logical['issuer'], $clientId, $clientSecret, $env);
        } elseif ($schema['deployment'] === 'openbao-backend') {
            $ok = $this->wireOpenBaoOidc($kubectl, $schema['namespace'], $ssoHost, $toolHost, $clientId, $clientSecret, $env);
        } else {
            $ok = $tool->usesCliOidc()
                ? $this->applyCliOidc($kubectl, $schema, $ssoHost, $clientId, $clientSecret)
                : $this->applyToolEnv($kubectl, $schema, $logical);
        }

        if (! $ok) {
            $this->laraKubeError("Failed to wire {$tool->getLabel()} to Zitadel.");

            return 1;
        }

        $this->laraKubeInfo("✅ {$tool->getLabel()} is wired to Zitadel SSO.");
        $this->newLine();

        if ($this->option('sso-only')) {
            $this->line("  <fg=yellow>🔒 SSO-only mode enabled — local password logins are disabled on {$tool->getLabel()}.</>");
            $this->line("  <fg=gray>To restore local password login, re-run:</> <fg=blue>larakube sso:wire {$env} --tool={$tool->value}</>");
            $this->newLine();
        }

        $this->line("  <fg=gray>Redirect URI registered:</> <fg=blue>https://{$toolHost}{$schema['redirect_path']}</>");
        $this->newLine();

        return 0;
    }

    /**
     * Ensure the RBAC project is set up to gate this tool: role-assertion
     * AND projectRoleCheck both on (see zitadelEnsureRbacProjectSettings()
     * — login is denied outright for anyone with zero roles on the project,
     * from the moment this runs, not as a later manual step), every role
     * rbacRoles() declares exists, and the org-wide claim-flattening Action
     * (see zitadelEnsureRbacAction()) is attached. Prints the roles for the
     * operator to grant via `sso:grant` — nobody, including the operator,
     * can SSO into a freshly-wired tool until that runs; OpenBao's root
     * token and Grafana's local admin login are unaffected (see
     * plans/active/openbao-hardening.md).
     *
     * Returns false — and the caller must stop, not proceed to wire
     * bound_claims/role_attribute_path against infrastructure that was
     * never confirmed to exist — if any step fails. This used to be void
     * and discard every step's result, which let zitadelEnsureRbacAction()
     * silently fail on every single run (a real, since-fixed body-encoding
     * bug) while `sso:wire` printed success regardless. Confirmed live
     * 2026-07-30 — caught by `sso:grant`, not by this command's own tests.
     */
    /**
     * $domainHint: the target instance's real host (e.g. 'notes.luchtech.dev'),
     * non-null only when this tool was wired against a named instance — shown
     * in the printed sso:grant hint as --domain=, since rbacProjectName()
     * being per-instance now means omitting it there would target a
     * DIFFERENT (empty) project than the one just configured here.
     */
    protected function ensureRbacGating(string $ssoHost, string $pat, string $projectId, ClusterTool $tool, ?string $domainHint = null): bool
    {
        $ok = true;
        $this->withSpin("Configuring role-gated access for {$tool->getLabel()}...", function () use ($ssoHost, $pat, $projectId, $tool, &$ok): void {
            $ok = $this->zitadelEnsureRbacProjectSettings($ssoHost, $pat, $projectId);
            if ($ok) {
                $ok = $this->zitadelEnsureRbacAction($ssoHost, $pat);
            }

            foreach ($tool->rbacRoles() as $roleKey => $label) {
                if (! $ok) {
                    break;
                }
                $ok = $this->zitadelEnsureProjectRole($ssoHost, $pat, $projectId, $roleKey, $label);
            }
        });

        if (! $ok) {
            return false;
        }

        $domainFlag = $domainHint !== null ? " --domain={$domainHint}" : '';

        $this->newLine();
        $this->line('  <fg=yellow>⚠ Role-gated tool — login is denied until you grant a role:</>');
        foreach ($tool->rbacRoles() as $roleKey => $label) {
            $this->line("    <fg=blue>{$roleKey}</> — {$label}");
        }
        $this->line("  <fg=gray>larakube sso:grant --tool={$tool->value}{$domainFlag} --role=<role> --email=<user></>");
        $this->line("  <fg=gray>Nobody, including you, can SSO into {$tool->getLabel()} until then — its own non-SSO admin access (if any) is unaffected.</>");
        $this->newLine();

        return true;
    }

    /**
     * Safety precondition for open-to-org tools that ship
     * PROXY_ROLE_ASSIGNMENT_DRIVER=oidc: the org-wide flattenOcisRoles
     * Action (always-emit ocisRoles claim) plus every ssoAdminRoles() role
     * on the tool's own project. Unlike ensureRbacGating() this does NOT
     * deny anyone login — the Action's ocisUser fallback keeps every org
     * member in — but without it driver=oidc would lock everyone out, so a
     * failure must stop the wire before the statics are applied.
     *
     * The tool's project must also carry projectRoleAssertion: Zitadel only
     * populates the Action's ctx.v1.user.grants for projects in the role
     * audience, and without the flag the grants resolve empty at runtime —
     * the Action falls back to ocisUser and every grantee silently lands on
     * the plain "user" role (the live "no + New Space button" bug on Drive,
     * 2026-08-02).
     *
     * Prints the admin roles and the sso:grant incantation for the operator.
     */
    protected function ensureSsoAdminGating(string $ssoHost, string $pat, string $projectId, ClusterTool $tool): bool
    {
        $ok = true;
        $this->withSpin("Configuring admin-role claims for {$tool->getLabel()}...", function () use ($ssoHost, $pat, $projectId, $tool, &$ok): void {
            $ok = $this->zitadelEnsureSsoAdminProjectSettings($ssoHost, $pat, $projectId);

            if ($ok) {
                $ok = $this->zitadelEnsureOcisRolesAction($ssoHost, $pat);
            }

            foreach ($tool->ssoAdminRoles() as $roleKey => $label) {
                if (! $ok) {
                    break;
                }
                $ok = $this->zitadelEnsureProjectRole($ssoHost, $pat, $projectId, $roleKey, $label);
            }
        });

        if (! $ok) {
            return false;
        }

        // "Open to org" is only true when this tool has NO rbacRoles() of its
        // own — Drive (2026-08-20) is the exception that has both: login
        // itself is already closed by the rbacRoles() gate printed above,
        // these are just the elevated tiers on top of it.
        $this->newLine();
        $this->line($tool->requiresRbacGating()
            ? '  <fg=blue>Admin tiers on top of the role-gated login above:</>'
            : '  <fg=blue>Open-to-org tool — every org member can log in. Admin roles:</>');
        foreach ($tool->ssoAdminRoles() as $roleKey => $label) {
            $this->line("    <fg=blue>{$roleKey}</> — {$label}");
        }
        $this->line("  <fg=gray>larakube sso:grant --tool={$tool->value} --role=<role> --email=<user> — or re-wire with --admin-email=</>");
        $this->newLine();

        return true;
    }

    /**
     * Grant --admin-email every ssoAdminRoles() role on the tool's own
     * project. Hard-fails (instead of warning and continuing) when the
     * email resolves to no Zitadel user — the whole point of --admin-email
     * is that this person IS the admin, so a typo'd address must surface.
     */
    protected function ensureSsoAdminGrant(string $ssoHost, string $pat, string $projectId, ClusterTool $tool, string $adminEmail): bool
    {
        $userId = $this->zitadelFindUserByEmail($ssoHost, $pat, $adminEmail);
        if ($userId === null) {
            $this->laraKubeError("No Zitadel user found for '{$adminEmail}' — create the user in Zitadel or fix --admin-email, then re-run.");

            return false;
        }

        foreach (array_keys($tool->ssoAdminRoles()) as $roleKey) {
            if (! $this->zitadelGrantRole($ssoHost, $pat, $userId, $projectId, $roleKey)) {
                $this->laraKubeError("Failed to grant '{$roleKey}' to {$adminEmail}.");

                return false;
            }
        }

        $granted = implode(', ', array_map(fn (string $k) => "'{$k}'", array_keys($tool->ssoAdminRoles())));
        $this->laraKubeInfo("✅ Granted {$granted} to {$adminEmail}.");

        return true;
    }

    protected function unwire(ClusterTool $tool, array $schema, string $kubectl, string $ssoNs, string $ssoHost, string $pat): int
    {
        if ($tool->usesForwardAuth()) {
            return $this->unwireForwardAuth($tool, $schema, $kubectl, $ssoNs, $ssoHost, $pat);
        }

        $appSecret = "sso-app-{$tool->value}";
        $projectId = $this->readClusterSecretKey($kubectl, $ssoNs, $appSecret, 'project-id');
        $appId = $this->readClusterSecretKey($kubectl, $ssoNs, $appSecret, 'app-id');

        if ($projectId !== null && $appId !== null) {
            $this->withSpin("Deregistering {$tool->getLabel()} from Zitadel...", fn () => $this->zitadelDeleteOidcApp($ssoHost, $pat, $projectId, $appId));
        }

        Process::run("{$kubectl} delete secret {$appSecret} -n {$ssoNs} --ignore-not-found");

        // Also drop the tool's own OIDC secret. The Deployment reads it via
        // optional valueFrom refs, so leaving it behind would let a later
        // :init / heal re-inject creds for the now-deregistered Zitadel app.
        Process::run("{$kubectl} delete secret {$schema['secret']} -n {$schema['namespace']} --ignore-not-found");

        if ($schema['deployment'] === 'chat-synapse') {
            $this->unwireSynapseOidc($kubectl, $schema['namespace']);
            Process::run("{$kubectl} rollout restart deployment/chat-synapse -n {$schema['namespace']}");
            $this->laraKubeInfo("✅ {$tool->getLabel()} no longer uses Zitadel SSO.");

            return 0;
        }

        if ($schema['deployment'] === 'openbao-backend') {
            $this->unwireOpenBaoOidc($kubectl, $schema['namespace']);
            $this->laraKubeInfo("✅ {$tool->getLabel()} no longer uses Zitadel SSO.");

            return 0;
        }

        $unset = array_values($schema['vars']);
        if (! empty($schema['static'])) {
            $unset = array_merge($unset, array_keys($schema['static']));
        }
        if (! empty($schema['sso_only_vars'])) {
            $unset = array_merge($unset, array_keys($schema['sso_only_vars']));
        }

        // CLI-wired tools hold the login source in their own DB — there is no env
        // to unset, so delete it the same way it was created.
        if ($tool->usesCliOidc()) {
            $exec = "{$kubectl} exec deploy/{$schema['deployment']} -n {$schema['namespace']} -- su-exec git forgejo --config /data/gitea/conf/app.ini admin auth";
            $sourceId = $this->findForgejoOidcSourceId($exec);
            if ($sourceId !== null) {
                $this->withSpin("Removing the Zitadel login source from {$tool->getLabel()}...", fn () => Process::run("{$exec} delete --id {$sourceId}")->successful());
            }

            $this->laraKubeInfo("✅ {$tool->getLabel()} no longer uses Zitadel SSO.");

            return 0;
        }

        $pairs = implode(' ', array_map(fn (string $key) => $key.'-', $unset));

        $ok = true;
        $this->withSpin("Unwiring {$tool->getLabel()} from Zitadel...", function () use ($kubectl, $schema, $pairs, &$ok): void {
            $ok = Process::run("{$kubectl} set env deployment/{$schema['deployment']} -n {$schema['namespace']} {$pairs}")->successful();
            if ($ok) {
                Process::run("{$kubectl} rollout restart deployment/{$schema['deployment']} -n {$schema['namespace']}");
            }
        });

        if (! $ok) {
            $this->laraKubeError("Failed to unwire {$tool->getLabel()} from Zitadel.");

            return 1;
        }

        $this->laraKubeInfo("✅ {$tool->getLabel()} no longer uses Zitadel SSO.");

        return 0;
    }

    /**
     * Register the OIDC login source inside the tool itself (Gitea keeps them in
     * its database, not in env). Idempotent: updates the existing source in
     * place when one is already present — matching the canonical `zitadel` name
     * as well as the legacy `Login with SSO` label, and renaming any legacy
     * source to `zitadel` so the callback path agrees with the redirect URI
     * registered in Zitadel. Mirrors how git:init checks `admin user list`
     * before creating its admin.
     *
     * @param  array{deployment: string, namespace: string, secret: string, vars: array<string, string>, redirect_path: string}  $schema
     */
    protected function applyCliOidc(string $kubectl, array $schema, string $ssoHost, string $clientId, string $clientSecret): bool
    {
        $ns = $schema['namespace'];
        $exec = "{$kubectl} exec deploy/{$schema['deployment']} -n {$ns} -- su-exec git forgejo --config /data/gitea/conf/app.ini admin auth";

        $existingId = $this->findForgejoOidcSourceId($exec);

        $args = '--name '.escapeshellarg('zitadel').' --provider openidConnect '
            .'--key '.escapeshellarg($clientId).' '
            .'--secret '.escapeshellarg($clientSecret).' '
            .'--auto-discover-url '.escapeshellarg("https://{$ssoHost}/.well-known/openid-configuration");

        $ok = false;
        $this->withSpin('Registering the Zitadel login source in Gitea...', function () use ($exec, $args, $existingId, &$ok) {
            $result = $existingId === null
                ? Process::run("{$exec} add-oauth {$args}")
                : Process::run("{$exec} update-oauth --id {$existingId} {$args}");

            $ok = $result->successful();
            if (! $ok) {
                $this->laraKubeLine('    '.trim($result->errorOutput() ?: $result->output()));
            }

            return $ok;
        });

        if ($ok) {
            // tool:list marks an OIDC tool as SSO-wired by probing for the
            // `{tool}-oidc` Secret (e.g. grafana-oidc) — every env-var-wired
            // tool gets one via applyToolEnv(). Forgejo's config lives in its
            // DB, so this CLI path was the only wiring that never wrote it,
            // and tool:list permanently reported a wired Forgejo as unwired.
            // Record the registration the same way, idempotently.
            Process::run(
                "{$kubectl} create secret generic {$schema['secret']} -n {$ns} "
                .'--from-literal=client-id='.escapeshellarg($clientId).' '
                .'--from-literal=client-secret='.escapeshellarg($clientSecret).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            // Gitea/Forgejo caches login sources in memory (a periodic
            // background sync, not an immediate reload) — update-oauth/
            // add-oauth only writes the DB row. Confirmed live 2026-08-21:
            // a freshly-rotated client-id 400'd with Zitadel's
            // "Errors.App.NotFound" for a real, unknown stretch of time
            // after the CLI reported success, because the running process
            // kept authorizing against the OLD client id it already had
            // cached. Every other OIDC-wired tool in this codebase restarts
            // after a config change for exactly this reason — this path
            // was the one exception.
            Process::run("{$kubectl} rollout restart deployment/{$schema['deployment']} -n {$ns}");
        }

        return $ok;
    }

    /** Namespace that hosts the one shared OAuth2-Proxy. */
    protected function proxyNamespace(): string
    {
        return 'larakube-shared';
    }

    /**
     * Gate a tool that has no native OIDC behind the SHARED OAuth2-Proxy, using
     * a Traefik ForwardAuth middleware on its Ingress.
     *
     * One proxy + one Zitadel app serve every gated tool: the callback lives on
     * a dedicated auth host, so adding a tool never touches Zitadel again.
     * See docs/decisions/0006-centralized-forwardauth-sso.md.
     *
     * @param  array{deployment: string, namespace: string, secret: string, vars: array<string, string>, redirect_path: string}  $schema
     */
    protected function wireForwardAuth(ClusterTool $tool, array $schema, string $kubectl, string $ssoNs, string $ssoHost, string $toolHost, string $pat, string $env, ?string $instance = null): int
    {
        $apex = $this->apexDomain($ssoHost);
        if ($apex === null) {
            $this->laraKubeError("Cannot derive a base domain from '{$ssoHost}' — ForwardAuth SSO needs a real domain.");

            return 1;
        }

        $authHost = "auth.{$apex}";
        // Cookies cannot be scoped to a single-label TLD (e.g. `.test`), so a
        // local *.test cluster can't share the session across hosts.
        $cookieDomain = str_contains($apex, '.') ? ".{$apex}" : null;
        if ($cookieDomain === null) {
            $this->laraKubeWarn("'{$apex}' is a single-label domain — the SSO session can't be shared across hosts, so login may loop locally.");
        }

        // Traefik ignores Middleware objects unless its CRDs are installed and
        // --providers.kubernetescrd is on. Fail loudly here instead of leaving a
        // registered Zitadel app and a running proxy that gate nothing.
        if (! $this->traefikMiddlewareCrdExists($kubectl)) {
            $this->laraKubeError('Traefik has no Middleware CRD on this cluster — ForwardAuth SSO cannot be attached.');
            $this->newLine();
            $this->line('  <fg=gray>LaraKube now ships the Traefik CRDs. Re-provision Traefik (</><fg=blue>larakube heal '.$env.'</><fg=gray>), then re-run this command.</>');
            $this->newLine();

            return 1;
        }

        // Mirrors wire()'s native-OIDC RBAC gating (see requiresRbacGating()) —
        // without this, --email-domain=* on the shared proxy below admits any
        // authenticated Zitadel user regardless of org, same class of gap
        // that let a partner org read internal Outline docs (2026-08-20).
        $rbacRole = null;
        if ($tool->requiresRbacGating()) {
            $projectId = $this->zitadelEnsureProject($ssoHost, $pat, $tool->rbacProjectName($instance));
            if ($projectId === null || ! $this->ensureRbacGating($ssoHost, $pat, $projectId, $tool, $instance !== null ? $toolHost : null)) {
                $this->laraKubeError(
                    "Could not set up role-gated access for {$tool->getLabel()} — the claim-flattening Action, ".
                    'project roles, or role assertion failed to apply. Gating the shared SSO proxy without '.
                    'confirmed authorization would risk denying every login, so this stops here.',
                );

                return 1;
            }
            $rbacRole = array_key_first($tool->rbacRoles());
        }

        $app = $this->ensureProxyOidcApp($kubectl, $ssoNs, $ssoHost, $authHost, $pat, $env);
        if ($app === null) {
            return 1;
        }

        // Stable across re-wires: regenerating this would sign every user out.
        // Length is re-validated because oauth2-proxy needs EXACTLY 16/24/32
        // bytes for its AES cipher and only auto-decodes base64url — plain
        // base64_encode(random_bytes(32)) yields 44 chars and crashloops the pod
        // ("cookie_secret must be 16, 24, or 32 bytes"). A rotten cached value
        // would otherwise be reused forever, so regenerate instead of trusting it.
        $cookieSecret = $this->readClusterSecretKey($kubectl, $this->proxyNamespace(), 'sso-proxy', 'OAUTH2_PROXY_COOKIE_SECRET');
        if ($cookieSecret === null || ! in_array(strlen($cookieSecret), [16, 24, 32], true)) {
            $cookieSecret = Str::random(32);
        }

        $ok = true;
        $this->withSpin('Deploying the shared SSO proxy...', function () use ($kubectl, $ssoHost, $authHost, $app, $cookieSecret, $cookieDomain, $env, $rbacRole, &$ok) {
            $ok = $this->applyManifest($kubectl, view('k8s.sso.proxy', [
                'namespace' => $this->proxyNamespace(),
                'ssoHost' => $ssoHost,
                'authHost' => $authHost,
                'clientId' => $app['clientId'],
                'clientSecret' => $app['clientSecret'],
                'cookieSecret' => $cookieSecret,
                'cookieDomain' => $cookieDomain,
                'isLocal' => $env === 'local',
                'proxied' => $this->ingressIsProxied($kubectl, 'sso-proxy', $this->proxyNamespace()),
                // sso-proxy is ONE shared pod across every ForwardAuth-gated
                // tool (ADR 0006) — this only stays correct while RECORD is
                // the sole ForwardAuth tool, which is true today. A second
                // ForwardAuth tool with a DIFFERENT role would silently
                // overwrite this on its own wire, since oauth2-proxy's
                // legacy single-provider mode has no per-route group scoping.
                // Needs real per-tool group scoping before that happens.
                'rbacRole' => $rbacRole,
                'secretChecksum' => substr(hash('sha256', $app['clientId'].$app['clientSecret'].$cookieSecret.($rbacRole ?? '')), 0, 16),
            ])->render(), 'sso-proxy');

            // task() only marks a step failed on an explicit false — without
            // this a failed apply still rendered a green tick.
            return $ok;
        });

        if ($ok) {
            $this->withSpin("Attaching the ForwardAuth middleware to {$tool->getLabel()}...", function () use ($kubectl, $schema, &$ok) {
                $ok = $this->applyManifest($kubectl, view('k8s.sso.forwardauth-middleware', [
                    'namespace' => $schema['namespace'],
                    'proxyNamespace' => $this->proxyNamespace(),
                ])->render(), 'sso-forwardauth');

                return $ok;
            });
        }

        if ($ok) {
            $ok = $this->applyToolIngress($kubectl, $tool, $toolHost, true, $env === 'local');
        }

        if (! $ok) {
            $this->laraKubeError("Failed to gate {$tool->getLabel()} behind the SSO proxy.");

            return 1;
        }

        Process::run("{$kubectl} rollout status deployment/sso-proxy -n {$this->proxyNamespace()} --timeout=120s");

        $this->laraKubeInfo("✅ {$tool->getLabel()} is gated behind Zitadel SSO.");
        $this->newLine();
        $this->line("  <fg=gray>Auth host:</>  <fg=blue>https://{$authHost}</> <fg=gray>— must resolve to this cluster (DNS).</>");
        $this->line("  <fg=gray>Gated URL:</>  <fg=blue>https://{$toolHost}</>");
        $this->newLine();
        if ($rbacRole !== null) {
            $this->line('  <fg=yellow>⚠ Role-gated tool — login is denied until you grant a role:</>');
            $this->line("    <fg=blue>{$rbacRole}</> — {$tool->rbacRoles()[$rbacRole]}");
            $this->line("  <fg=gray>larakube sso:grant --tool={$tool->value} --role={$rbacRole} --email=<user></>");
            $this->line("  <fg=gray>Nobody, including you, can reach {$tool->getLabel()} until then — its own non-SSO admin access (if any) is unaffected.</>");
        } else {
            $this->line('  <fg=yellow>Note:</> this is an access gate, not an app login — anyone with a Zitadel');
            $this->line("  <fg=gray>      </> account can reach it, and {$tool->productName()} keeps its own accounts.");
        }
        $this->newLine();

        return 0;
    }

    /**
     * @param  array{deployment: string, namespace: string, secret: string, vars: array<string, string>, redirect_path: string}  $schema
     */
    protected function unwireForwardAuth(ClusterTool $tool, array $schema, string $kubectl, string $ssoNs, string $ssoHost, string $pat): int
    {
        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;
        $toolHost = $this->targetHost($tool, $env, $config, $kubectl);

        $ok = true;
        if ($toolHost !== null) {
            $ok = $this->applyToolIngress($kubectl, $tool, $toolHost, false, $env === 'local');
        }

        Process::run("{$kubectl} delete middleware sso-forwardauth -n {$schema['namespace']} --ignore-not-found");

        // The proxy is SHARED — only tear it down once nothing else is gated.
        if ($this->gatedForwardAuthTools($kubectl, $tool) === []) {
            $this->withSpin('No gated tools left — removing the shared SSO proxy...', function () use ($kubectl, $ssoNs, $ssoHost, $pat): void {
                $projectId = $this->readClusterSecretKey($kubectl, $ssoNs, 'sso-app-proxy', 'project-id');
                $appId = $this->readClusterSecretKey($kubectl, $ssoNs, 'sso-app-proxy', 'app-id');
                if ($projectId !== null && $appId !== null) {
                    $this->zitadelDeleteOidcApp($ssoHost, $pat, $projectId, $appId);
                }

                $ns = $this->proxyNamespace();
                Process::run("{$kubectl} delete ingress sso-proxy -n {$ns} --ignore-not-found");
                Process::run("{$kubectl} delete service sso-proxy -n {$ns} --ignore-not-found");
                Process::run("{$kubectl} delete deployment sso-proxy -n {$ns} --ignore-not-found");
                Process::run("{$kubectl} delete secret sso-proxy -n {$ns} --ignore-not-found");
                Process::run("{$kubectl} delete secret sso-app-proxy -n {$ssoNs} --ignore-not-found");
            });
        }

        if (! $ok) {
            $this->laraKubeError("Failed to unwire {$tool->getLabel()} from Zitadel.");

            return 1;
        }

        $this->laraKubeInfo("✅ {$tool->getLabel()} is no longer gated behind Zitadel SSO.");

        return 0;
    }

    /**
     * Register (or reuse) the ONE OIDC app that backs the shared proxy. Its
     * redirect URI is the fixed auth host, so it never needs updating.
     *
     * @return array{clientId: string, clientSecret: string}|null
     */
    protected function ensureProxyOidcApp(string $kubectl, string $ssoNs, string $ssoHost, string $authHost, string $pat, string $env): ?array
    {
        $clientId = $this->readClusterSecretKey($kubectl, $ssoNs, 'sso-app-proxy', 'client-id');
        $clientSecret = $this->readClusterSecretKey($kubectl, $ssoNs, 'sso-app-proxy', 'client-secret');
        $appId = $this->readClusterSecretKey($kubectl, $ssoNs, 'sso-app-proxy', 'app-id');
        $projectId = $this->readClusterSecretKey($kubectl, $ssoNs, 'sso-app-proxy', 'project-id');

        if ($clientId !== null && $clientSecret !== null && $appId !== null && $projectId !== null
            && ZitadelConnector::make($ssoHost, $pat)->send(GetProjectAppRequest::make($projectId, $appId))->successful()) {
            return ['clientId' => $clientId, 'clientSecret' => $clientSecret];
        }

        $projectName = (string) ($this->option('project') ?: 'LaraKube Shared Tools');
        $registered = null;
        $this->withSpin('Registering the shared SSO proxy in Zitadel...', function () use (&$registered, $ssoHost, $pat, $projectName, $authHost): void {
            $projectId = $this->zitadelEnsureProject($ssoHost, $pat, $projectName);
            if ($projectId === null) {
                return;
            }

            $app = $this->zitadelCreateOidcApp($ssoHost, $pat, $projectId, 'LaraKube SSO Proxy', ["https://{$authHost}/oauth2/callback"]);
            if ($app === null) {
                return;
            }

            $registered = array_merge($app, ['projectId' => $projectId]);
        });

        if ($registered === null) {
            $this->laraKubeError("Could not register the SSO proxy in Zitadel — check the automation credentials and Zitadel's own logs.");

            return null;
        }

        Process::run(
            "{$kubectl} create secret generic sso-app-proxy -n {$ssoNs} "
            .'--from-literal=project-id='.escapeshellarg($registered['projectId']).' '
            .'--from-literal=app-id='.escapeshellarg($registered['appId']).' '
            .'--from-literal=client-id='.escapeshellarg($registered['clientId']).' '
            .'--from-literal=client-secret='.escapeshellarg($registered['clientSecret']).' '
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        );

        if ($this->secretsBackendAvailable($kubectl)) {
            $clusterEnv = $env === 'local' ? 'dev' : $env;
            $this->pushClusterSecret($kubectl, 'SSO_PROXY_OIDC_CLIENT_ID', $registered['clientId'], $clusterEnv);
            $this->pushClusterSecret($kubectl, 'SSO_PROXY_OIDC_CLIENT_SECRET', $registered['clientSecret'], $clusterEnv);
        }

        return ['clientId' => $registered['clientId'], 'clientSecret' => $registered['clientSecret']];
    }

    /**
     * Every OTHER ForwardAuth tool whose Ingress still carries the middleware.
     *
     * @return list<string>
     */
    protected function gatedForwardAuthTools(string $kubectl, ClusterTool $except): array
    {
        $gated = [];
        foreach (ClusterTool::cases() as $candidate) {
            if ($candidate === $except || ! $candidate->usesForwardAuth()) {
                continue;
            }

            $annotations = Process::run(
                "{$kubectl} get ingress {$candidate->value} -n {$candidate->namespace()} "
                ."-o jsonpath='{.metadata.annotations}' --ignore-not-found",
            )->output();

            if (str_contains($annotations, 'sso-forwardauth')) {
                $gated[] = $candidate->value;
            }
        }

        return $gated;
    }

    /** Re-render a tool's own Ingress with the SSO middleware on or off. */
    protected function applyToolIngress(string $kubectl, ClusterTool $tool, string $host, bool $ssoWired, bool $isLocal): bool
    {
        $view = "k8s.{$tool->value}.ingress";
        if (! view()->exists($view)) {
            $this->laraKubeError("No ingress template for '{$tool->value}' — cannot toggle its SSO middleware.");

            return false;
        }

        return $this->applyManifest($kubectl, view($view, [
            'host' => $host,
            'ssoWired' => $ssoWired,
            'isLocal' => $isLocal,
            'vpnOnly' => $this->toolIngressUsesVpn($kubectl, $tool),
            'proxied' => $this->toolIngressIsProxied($kubectl, $tool),
        ])->render(), "{$tool->value}-ingress");
    }

    /** Preserve an existing vpn-only middleware when re-rendering the Ingress. */
    protected function toolIngressUsesVpn(string $kubectl, ClusterTool $tool): bool
    {
        return str_contains(Process::run(
            "{$kubectl} get ingress {$tool->value} -n {$tool->namespace()} "
            ."-o jsonpath='{.metadata.annotations}' --ignore-not-found",
        )->output(), 'vpn-only');
    }

    /** Preserve the Cloudflare proxy mode when re-rendering an Ingress. */
    protected function toolIngressIsProxied(string $kubectl, ClusterTool $tool): bool
    {
        return $this->ingressIsProxied($kubectl, $tool->value, $tool->namespace());
    }

    protected function ingressIsProxied(string $kubectl, string $name, string $namespace): bool
    {
        return str_contains(Process::run(
            "{$kubectl} get ingress {$name} -n {$namespace} "
            ."-o jsonpath='{.metadata.annotations}' --ignore-not-found",
        )->output(), 'cloudflare-proxied');
    }

    protected function applyManifest(string $kubectl, string $yaml, string $name): bool
    {
        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path("larakube-{$name}.yaml");
        file_put_contents($tmp, $yaml);
        $result = Process::run("{$kubectl} apply -f {$tmp}");
        $temporaryDirectory->delete();

        return $result->successful();
    }

    /** Is Traefik's Middleware CRD registered on this cluster? */
    protected function traefikMiddlewareCrdExists(string $kubectl): bool
    {
        return trim(Process::run(
            "{$kubectl} get crd middlewares.traefik.io --no-headers --ignore-not-found",
        )->output()) !== '';
    }

    /** `sso.luchtech.dev` → `luchtech.dev`; null when there's nothing to strip. */
    protected function apexDomain(string $host): ?string
    {
        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return null;
        }

        return implode('.', array_slice($parts, 1));
    }

    /**
     * @param  array{deployment: string, namespace: string, secret: string, static?: array<string, string>, vars: array<string, string>, string_cast?: list<string>}  $schema
     * @param  array<string, string>  $logical
     */
    protected function applyToolEnv(string $kubectl, array $schema, array $logical): bool
    {
        $staticVars = $schema['static'] ?? [];
        $isPenpot = str_starts_with($schema['deployment'], 'design-penpot-backend');
        // Instance suffix (e.g. '-design-luchtech-dev', or '' for the bare
        // legacy name) — derived from the deployment name so the smtp/oidc
        // secret and frontend deployment names below always match the same
        // instance $schema['secret']/$schema['deployment'] already resolved to.
        $penpotSuffix = $isPenpot ? substr($schema['deployment'], strlen('design-penpot-backend')) : '';

        // PENPOT_FLAGS is reconciled from scratch by ReconcilesPenpotFlags,
        // not carried through the generic static-var plumbing below — see
        // docs/decisions/0013-design-init-idempotent-flags.md. Computed
        // AFTER the secret is (re)written below, not here — this secret may
        // not have real OIDC credentials yet on a first-ever wire (or after
        // a rename/recreate), and resolveDesignPenpotFlags() reads the
        // secret's current state, so computing it before the write below
        // would see stale/empty data and silently omit
        // 'enable-login-with-oidc' — confirmed live 2026-08-17 (Design).
        $ssoOnlyOption = (bool) $this->option('sso-only');

        // PENPOT_FLAGS is reconciled separately (ReconcilesPenpotFlags,
        // below) — excluded from the generic static-var plumbing so its
        // computed-fresh-every-time value is never shadowed by a stale
        // literal here.
        unset($staticVars['PENPOT_FLAGS']);

        // sso_only_vars must be folded into $staticVars BEFORE $literals is
        // built below — see docs/decisions/0018-wire-commands-never-literal-env.md
        // point 6. Previously this merge happened after the secret write, so
        // these vars only ever reached the Deployment through a literal
        // `kubectl set env` override that's now removed; merging early means
        // they ride the same Secret + `--from=secret` path as everything else.
        $unsetPairs = '';
        if ($ssoOnlyOption && ! empty($schema['sso_only_vars'])) {
            $staticVars = array_merge($staticVars, $schema['sso_only_vars']);
        } elseif (! empty($schema['sso_only_vars'])) {
            // Turning --sso-only OFF: these vars have no declarative
            // "absent" state if a PAST run wrote them literally — that's the
            // one case still requiring an imperative `KEY-` unset. Removing
            // a key never produces the value+valueFrom conflict; only
            // ADDING a literal value does. See ADR 0018 point 4.
            foreach ($schema['sso_only_vars'] as $k => $v) {
                if (! isset($staticVars[$k])) {
                    $unsetPairs .= ' '.$k.'-';
                }
            }
        }

        $literals = '';
        foreach ($staticVars as $envName => $value) {
            $literals .= '--from-literal='.$envName.'='.escapeshellarg($value).' ';
        }
        foreach ($schema['vars'] as $key => $envName) {
            if (isset($logical[$key])) {
                $value = $logical[$key];
                // Directus auto-casts all-digit env values to a JS number, which
                // breaks openid-client's Client constructor ("client_id is
                // required") — Zitadel issues purely-numeric client IDs. The
                // `string:` prefix is Directus's own documented escape hatch.
                if (in_array($key, $schema['string_cast'] ?? [], true) && preg_match('/^\d+$/', $value)) {
                    $value = 'string:'.$value;
                }
                $literals .= '--from-literal='.$envName.'='.escapeshellarg($value).' ';
            }
        }

        $secret = $schema['secret'];
        $deployment = $schema['deployment'];
        $ns = $schema['namespace'];

        $ok = true;
        $this->withSpin("Wiring {$deployment}...", function () use ($kubectl, $ns, $secret, $literals, $deployment, $schema, $isPenpot, $penpotSuffix, $ssoOnlyOption, $unsetPairs, &$ok): void {
            Process::run(
                "{$kubectl} create secret generic {$secret} -n {$ns} {$literals}--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            $set = Process::run("{$kubectl} set env deployment/{$deployment} --from=secret/{$secret} -n {$ns}");
            $ok = $set->successful();

            // ADR 0018: every value that belongs on the Deployment is already
            // in the Secret and already applied declaratively via --from=secret
            // above. The only thing left to do imperatively is REMOVE a var
            // that has no place in the Secret at all (an --sso-only toggle-off)
            // — never re-apply a value as a literal `value:` override, which
            // would desync `kubectl apply`'s bookkeeping for every future
            // `{tool}:init` re-run.
            if ($ok && $unsetPairs !== '') {
                $ok = Process::run("{$kubectl} set env deployment/{$deployment} -n {$ns}{$unsetPairs}")->successful();
            }

            foreach ($schema['also_patch'] ?? [] as $secondaryDeployment) {
                if (! $ok) {
                    break;
                }
                $ok = Process::run("{$kubectl} set env deployment/{$secondaryDeployment} --from=secret/{$secret} -n {$ns}")->successful();
                if ($ok) {
                    Process::run("{$kubectl} rollout restart deployment/{$secondaryDeployment} -n {$ns}");
                }
            }

            if ($ok) {
                Process::run("{$kubectl} rollout restart deployment/{$deployment} -n {$ns}");
                $this->forceExternalSecretReconcile($kubectl, $ns, $secret);
            }

            if ($ok && $isPenpot) {
                $penpotFlags = $this->resolveDesignPenpotFlags(
                    $kubectl,
                    $ns,
                    $secret,
                    "design-smtp{$penpotSuffix}",
                    $ssoOnlyOption,
                    $deployment,
                );
                $this->applyDesignPenpotFlags($kubectl, $ns, $secret, $penpotFlags, $deployment, ...($schema['also_patch'] ?? ["design-penpot-frontend{$penpotSuffix}"]));
            }
        });

        return $ok;
    }

    protected function targetHost(ClusterTool $tool, string $env, ?ConfigData $config, ?string $kubectl = null): ?string
    {
        // Every OIDC-capable tool resolves its host the same read-only way its
        // own resolve*HostReadOnly() does — through its SharedClusterService.
        // Keying off $tool->service() means a tool becomes wireable the moment
        // it has an oidcEnv() schema, with no per-tool case to remember here —
        // the omission that silently broke sign/notes/drive/tasks.
        $service = $tool->service();
        if ($service === null) {
            return null;
        }

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        // ResolvesToolHost::promptForCloudHost() persists cloud tool hosts to
        // the CLUSTER REGISTRY, not .larakube.json — dashboard:init never
        // writes a project file at all. Check the registry (and, failing
        // that, the tool's live Ingress) before falling back to the project
        // file, or any tool onboarded after that migration is permanently
        // unwireable: "No host is configured" even though its :init clearly
        // ran and recorded one. Confirmed live 2026-08-06 (Headlamp).
        if ($kubectl !== null) {
            $registered = $this->resolveLiveToolHost($kubectl, $tool);
            if ($registered !== null && $registered !== '') {
                return $registered;
            }
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function resolveTool(?string $kubectl = null): ?ClusterTool
    {
        $slug = (string) ($this->option('tool') ?: '');
        if ($slug !== '') {
            $tool = ClusterTool::tryFrom($slug);
            if ($tool === null) {
                $this->laraKubeError("Unknown tool '{$slug}'.");

                return null;
            }
            if ($this->refuseUnshippedTool($tool)) {
                return null;
            }

            return $tool;
        }

        $capable = array_values(array_filter(ClusterTool::shippedCases(), fn (ClusterTool $t) => $t->hasSsoWire()));
        $installed = $kubectl !== null
            ? array_values(array_filter($capable, function (ClusterTool $t) use ($kubectl) {
                if ($t === ClusterTool::CHAT) {
                    return $this->deploymentExists($kubectl, 'larakube-shared', 'chat-synapse');
                }

                if ($t === ClusterTool::DATA) {
                    return $this->deploymentExists($kubectl, 'larakube-shared', 'data-pocketbase')
                        || $this->deploymentExists($kubectl, 'larakube-shared', 'data-directus')
                        || trim(Process::run(
                            "{$kubectl} get deployment -n larakube-shared -l 'app.kubernetes.io/component=data' --no-headers --ignore-not-found",
                        )->output()) !== '';
                }

                $schema = $t->oidcEnv();

                return $schema !== null && $this->deploymentExists($kubectl, $schema['namespace'], $schema['deployment']);
            }))
            : $capable;

        if ($installed === []) {
            $this->laraKubeError('No OIDC-capable tools (e.g. Vaultwarden, Grafana) are currently installed on this cluster.');

            return null;
        }

        $options = [];
        foreach ($installed as $t) {
            $options[$t->value] = $t->getLabel();
        }

        return ClusterTool::from(select(
            label: 'Wire which tool to Zitadel SSO?',
            options: $options,
            scroll: count($options),
        ));
    }

    protected function deploymentExists(string $kubectl, string $ns, string $deployment): bool
    {
        return trim(Process::run(
            "{$kubectl} get deployment {$deployment} -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';
    }

    /**
     * Persist the OIDC credentials to the `chat-oidc` Secret (so `chat:init`
     * re-renders the oidc_providers: block on re-run) and apply them to
     * Synapse's homeserver.yaml Secret. Preserves any existing `email:` block.
     * Issues a rollout restart so Synapse picks up the new config immediately.
     *
     * @return bool true on success
     */
    protected function wireSynapseOidc(
        string $kubectl,
        string $ns,
        string $ssoHost,
        string $issuer,
        string $clientId,
        string $clientSecret,
        string $env,
    ): bool {
        // 1. Persist credentials to the chat-oidc Secret so chat:init can
        //    re-render the oidc_providers: block on a re-run.
        Process::run(
            "{$kubectl} create secret generic chat-oidc -n {$ns} "
            .'--from-literal=issuer='.escapeshellarg($issuer).' '
            .'--from-literal=client-id='.escapeshellarg($clientId).' '
            .'--from-literal=client-secret='.escapeshellarg($clientSecret).' '
            .'--from-literal=name=Zitadel '
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        );

        // 2. Re-render homeserver.yaml with the oidc_providers: block,
        //    preserving any existing email: block (same read-back discipline).
        $smtp = $this->readChatWiredSmtp($kubectl, $ns);
        $oidc = [
            'issuer' => $issuer,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'name' => 'Zitadel',
        ];
        // If chat:init has already activated MAS-delegated auth (chat-oidc
        // was absent when it ran), a plain `sso:wire chat` re-run must not
        // silently regress it back to
        // classic oidc_providers: — renderSynapseConfig() always prefers
        // $mas when both are present, so re-registering the (now-inert)
        // Synapse-native Zitadel app above stays harmless.
        $mas = $this->readChatWiredMas($kubectl, $ns);

        $raw = trim(Process::run(
            "{$kubectl} get secret chat-synapse-config -n {$ns} -o jsonpath='{.data.homeserver\.yaml}'",
        )->output());

        if ($raw === '') {
            return false;
        }

        $rawYaml = (string) base64_decode($raw);
        $homeserver = $this->renderSynapseConfig($rawYaml, $smtp, $oidc, $mas);

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmp = $temporaryDirectory->path().'/homeserver.yaml';
        file_put_contents($tmp, $homeserver);
        $result = Process::run(
            "{$kubectl} create secret generic chat-synapse-config -n {$ns} "
            ."--from-file=homeserver.yaml={$tmp} "
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        );
        $temporaryDirectory->delete();

        if ($result->successful()) {
            Process::run("{$kubectl} rollout restart deployment/chat-synapse -n {$ns}");
        }

        return $result->successful();
    }

    /**
     * Remove the `oidc_providers:` block from Synapse's homeserver.yaml Secret,
     * delete the `chat-oidc` credential Secret, and restart the pod.
     * Preserves any existing `email:` block.
     */
    protected function unwireSynapseOidc(string $kubectl, string $ns): void
    {
        // Delete the chat-oidc credential Secret first so chat:init won't
        // re-render the oidc_providers: block on the next run.
        Process::run("{$kubectl} delete secret chat-oidc -n {$ns} --ignore-not-found");

        $smtp = $this->readChatWiredSmtp($kubectl, $ns);
        $mas = $this->readChatWiredMas($kubectl, $ns);

        $raw = trim(Process::run(
            "{$kubectl} get secret chat-synapse-config -n {$ns} -o jsonpath='{.data.homeserver\.yaml}'",
        )->output());

        if ($raw === '') {
            return;
        }

        $rawYaml = (string) base64_decode($raw);
        $homeserver = $this->renderSynapseConfig($rawYaml, $smtp, null, $mas);

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmp = $temporaryDirectory->path().'/homeserver.yaml';
        file_put_contents($tmp, $homeserver);
        $result = Process::run(
            "{$kubectl} create secret generic chat-synapse-config -n {$ns} "
            ."--from-file=homeserver.yaml={$tmp} "
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        );
        $temporaryDirectory->delete();

        if ($result->successful()) {
            Process::run("{$kubectl} rollout restart deployment/chat-synapse -n {$ns}");
        }
    }

    /**
     * Enable the OIDC auth backend on OpenBao, configure it to use Zitadel,
     * and write three role-gated roles (admin/operator/auditor) instead of
     * one unconditional-admin role. Idempotent: skips `bao auth enable oidc`
     * when the backend is already mounted; policies/roles/config are
     * (re)written every run, which is a no-op when unchanged and
     * self-heals drift when not.
     *
     * bound_claims matches against `larakube_roles`, a flat array claim that
     * only exists because ensureRbacGating() (called from wire() before
     * this) attaches zitadelEnsureRbacAction()'s Action to Zitadel's
     * Complement Token flow — Zitadel's native roles claim is a nested
     * object bound_claims cannot read. See plans/active/openbao-hardening.md.
     */
    protected function wireOpenBaoOidc(
        string $kubectl,
        string $ns,
        string $ssoHost,
        string $toolHost,
        string $clientId,
        string $clientSecret,
        string $env,
    ): bool {
        $rootToken = $this->readClusterSecretKey($kubectl, $ns, 'openbao-bootstrap', 'root-token');
        if ($rootToken === null) {
            $this->laraKubeError('OpenBao is not initialized — no root token found. Run `larakube secrets:import` first.');

            return false;
        }

        // -i is required: the policy-write loop below pipes HCL into `bao
        // policy write NAME -` over stdin, and `kubectl exec` drops piped
        // stdin silently unless told to forward it. Without it, bao gets an
        // empty payload and every policy write 400s with "'policy'
        // parameter not supplied or empty" — confirmed live on
        // 2026-07-30 (this bug predates this rewrite but was never
        // exercised: the old code only wrote the `admin` policy when it
        // didn't already exist, which was never true on this cluster).
        $exec = "{$kubectl} exec -i deploy/openbao-backend -n {$ns} -- env "
            .'BAO_TOKEN='.escapeshellarg($rootToken).' '
            .'BAO_ADDR=http://127.0.0.1:8200';

        // Shared with SecretsInitCommand's baseline userpass admin — see
        // SecretsBackend::policies()'s docblock for why these must not be
        // two independently-maintained copies.
        $policies = SecretsBackend::OPENBAO->policies();

        $ok = true;
        $this->withSpin('Enabling OIDC auth backend on OpenBao...', function () use ($exec, $ssoHost, $clientId, $clientSecret, $toolHost, $policies, &$ok): void {
            $list = Process::run("{$exec} bao auth list -format=json")->output();
            if (! str_contains($list, '"oidc/"')) {
                $ok = Process::run("{$exec} bao auth enable oidc")->successful();
            }

            foreach ($policies as $name => $hcl) {
                if (! $ok) {
                    break;
                }
                $ok = Process::run(
                    'printf "%s" '.escapeshellarg($hcl).' | '.$exec.' bao policy write '.$name.' -',
                )->successful();
            }

            if ($ok) {
                $ok = Process::run(
                    "{$exec} bao write auth/oidc/config "
                    .'oidc_discovery_url='.escapeshellarg("https://{$ssoHost}").' '
                    .'oidc_client_id='.escapeshellarg($clientId).' '
                    .'oidc_client_secret='.escapeshellarg($clientSecret),
                )->successful();
            }

            // Unconditional, not migration-detection: the pre-hardening
            // setup wrote a `user` role with no bound_claims (unconditional
            // admin) and default_role=user. That role is no longer
            // referenced by config above, but stays selectable by name
            // (?role=user) until deleted — a no-op if it never existed.
            if ($ok) {
                Process::run("{$exec} bao delete auth/oidc/role/user");
            }

            $redirectUris = ["https://{$toolHost}/v1/auth/oidc/oidc/callback", "https://{$toolHost}/ui/vault/auth/oidc/oidc/callback"];
            $tiers = ['admin' => 'openbao-admin', 'operator' => 'openbao-operator', 'auditor' => 'openbao-auditor'];
            foreach ($tiers as $role => $roleKey) {
                if (! $ok) {
                    break;
                }

                // `bao write PATH key=value...` always sends each value as a
                // JSON string — bound_claims needs the "map" type, and
                // OpenBao's server never coerces a string into one, so
                // bound_claims='{"...":"..."}' 400s with "unconvertible
                // type 'string'" even though the string IS valid JSON.
                // Piping the whole role as one JSON document via `write
                // PATH -` sidesteps the CLI's flat k=v parser entirely, so
                // bound_claims parses as a real object. Confirmed live
                // 2026-07-30 after the first fix (adding -i) alone still
                // failed on this.
                // max_age is a per-role field (duration seconds), not a
                // config-level one — auth/oidc/config has no such parameter
                // at all in this OpenBao version and silently drops unknown
                // fields instead of erroring, which is how the original
                // config-level max_age=3600 shipped without any write
                // failing. Confirmed via `bao path-help` on both paths.
                $roleJson = json_encode([
                    'allowed_redirect_uris' => $redirectUris,
                    'user_claim' => 'sub',
                    'bound_claims' => ['larakube_roles' => $roleKey],
                    'bound_claims_type' => 'string',
                    'policies' => ["{$role}-policy"],
                    'default_ttl' => '30m',
                    'max_ttl' => '4h',
                    'max_age' => '3600',
                ]);
                $ok = Process::run(
                    'printf "%s" '.escapeshellarg((string) $roleJson).' | '.$exec." bao write auth/oidc/role/{$role} -",
                )->successful();
            }
        });

        if ($ok && $this->secretsBackendAvailable($kubectl)) {
            $clusterEnv = $env === 'local' ? 'dev' : $env;
            $this->pushClusterSecret($kubectl, 'OPENBAO_OIDC_CLIENT_ID', $clientId, $clusterEnv);
            $this->pushClusterSecret($kubectl, 'OPENBAO_OIDC_CLIENT_SECRET', $clientSecret, $clusterEnv);
        }

        if ($ok) {
            // tool:list marks an OIDC tool as SSO-wired by probing for the
            // `{tool}-oidc` Secret (openbao-oidc, per SecretTool::oidcEnv()).
            // OpenBao's wiring lives in its own storage (`bao auth enable
            // oidc` above), so this CLI path is what must record the marker
            // secret — every env-var-wired tool gets one from applyToolEnv().
            // Without it, tool:list reports a login that works as unwired.
            Process::run(
                "{$kubectl} create secret generic openbao-oidc -n {$ns} "
                .'--from-literal=client-id='.escapeshellarg($clientId).' '
                .'--from-literal=client-secret='.escapeshellarg($clientSecret).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        }

        return $ok;
    }

    /** Disable the OIDC auth backend on OpenBao. */
    protected function unwireOpenBaoOidc(string $kubectl, string $ns): void
    {
        $rootToken = $this->readClusterSecretKey($kubectl, $ns, 'openbao-bootstrap', 'root-token');
        if ($rootToken === null) {
            return;
        }

        $exec = "{$kubectl} exec deploy/openbao-backend -n {$ns} -- env "
            .'BAO_TOKEN='.escapeshellarg($rootToken).' '
            .'BAO_ADDR=http://127.0.0.1:8200';

        Process::run("{$exec} bao auth disable oidc");
    }
}
