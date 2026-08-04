<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\ClusterTool;

/**
 * Per-app/per-environment OpenBao access — the narrow counterpart to the
 * fixed 3-tier openbao-admin/operator/auditor roles wireOpenBaoOidc() sets
 * up. Those are cluster-wide; this lets `secrets:grant` hand a developer
 * read-write on exactly one app's one environment (secret/data/{env}/{app}/*)
 * without seeing any other app's or environment's secrets — the same
 * OIDC auth backend, policy engine, and RBAC project (LaraKube RBAC) as the
 * fixed tiers, just a dynamically-minted role key per (app, environment,
 * tier) instead of one of the three static ones.
 *
 * Deliberately reuses InteractsWithSsoGrants' connection/project resolution
 * and InteractsWithZitadelApi's grant primitives rather than duplicating
 * them — secrets:grant/secrets:revoke are a second command PAIR (not folded
 * into sso:grant/sso:revoke, so the per-app UX stays simple), but they are
 * NOT a second system: sso:revoke's discovery already finds and revokes
 * these role keys too (see ClusterTool::forGrantableRoleKey()'s "secrets-"
 * prefix match), so there is exactly one place that answers "what does this
 * user have" and exactly one command that can wipe it all in an incident.
 */
trait InteractsWithAppSecretGrants
{
    use InteractsWithSecrets, InteractsWithSsoGrants;

    /** The app name secrets are scoped under — --app, or this project's own name. */
    protected function resolveGrantApp(?ConfigData $config): string
    {
        $flag = (string) ($this->option('app') ?: '');
        if ($flag !== '') {
            return $flag;
        }

        return $config?->getName() ?? basename(getcwd());
    }

    /**
     * The dynamic Zitadel project role key AND OpenBao auth-role/policy name
     * for one (app, environment, tier) grant — one string ties all three
     * together, so "does this user hold X" and "what does OpenBao trust for
     * X" are never at risk of drifting apart. Pure.
     */
    protected function appSecretsRoleKey(string $app, string $environment, string $role): string
    {
        return "secrets-{$app}-{$environment}-{$role}";
    }

    /**
     * The HCL policy body for one (app, environment, tier) grant, scoped
     * strictly to that app's slice of that environment — matching the
     * secret/data/{environment}/{app}/{key} path dotenv:push composes.
     * `developer` gets create/read/update/patch/list; `viewer` read/list only.
     * Neither ever gets `delete` — that stays reserved for the cluster-wide
     * openbao-admin tier. Pure.
     */
    protected function appSecretsPolicyHcl(string $app, string $environment, string $role): string
    {
        $capabilities = $role === 'developer'
            ? '["create", "read", "update", "patch", "list"]'
            : '["read", "list"]';

        return 'path "secret/data/'.$environment.'/'.$app.'/*" { capabilities = '.$capabilities.' }'
            ."\n".'path "secret/metadata/'.$environment.'/'.$app.'/*" { capabilities = ["read", "list"] }'
            ."\n".'path "sys/internal/ui/mounts" { capabilities = ["read"] }'
            ."\n".'path "sys/internal/ui/mounts/*" { capabilities = ["read"] }';
    }

    /**
     * Ensure OpenBao trusts this (app, environment, tier) role key: write its
     * policy, then bind an OIDC auth role to it so a Zitadel grant of the
     * SAME key (appSecretsRoleKey()) is enough to log in with it. Idempotent
     * — re-running for an existing grant just rewrites the same policy/role,
     * a no-op when unchanged, self-healing when not. Uses openBaoApi()'s
     * direct HTTP JSON path (not the kubectl-exec `bao` CLI piping
     * wireOpenBaoOidc() needs) — bound_claims is a real nested JSON object
     * here, not flattened through the CLI's k=v parser, so the map-type 400
     * that piping works around never applies.
     *
     * $toolHost is OpenBao's OWN host (secrets.{domain}) — the redirect URI
     * a role's OIDC login round-trips through, NOT Zitadel's host. The
     * shared oidc_discovery_url/client_id/secret config it authenticates
     * against is wired once for the whole auth/oidc mount by
     * SsoWireCommand::wireOpenBaoOidc(); only per-role bound_claims/
     * policies/redirect_uris differ here.
     */
    protected function ensureAppSecretsWiring(string $kubectl, string $app, string $environment, string $role, string $toolHost): bool
    {
        $secretsNs = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $secretsNs, 'root-token');
        if ($token === null) {
            $this->laraKubeError('OpenBao is not reachable — is it installed and unsealed?');

            return false;
        }

        $roleKey = $this->appSecretsRoleKey($app, $environment, $role);
        $policyName = "{$roleKey}-policy";

        $wrotePolicy = $this->openBaoApi(
            $kubectl,
            'PUT',
            "/v1/sys/policies/acl/{$policyName}",
            ['policy' => $this->appSecretsPolicyHcl($app, $environment, $role)],
            $token,
        );
        if ($wrotePolicy === null) {
            return false;
        }

        $wroteRole = $this->openBaoApi($kubectl, 'PUT', "/v1/auth/oidc/role/{$roleKey}", [
            'allowed_redirect_uris' => [
                "https://{$toolHost}/v1/auth/oidc/oidc/callback",
                "https://{$toolHost}/ui/vault/auth/oidc/oidc/callback",
            ],
            'user_claim' => 'sub',
            'bound_claims' => ['larakube_roles' => $roleKey],
            'bound_claims_type' => 'string',
            'policies' => [$policyName],
            'default_ttl' => '30m',
            'max_ttl' => '4h',
        ], $token);

        return $wroteRole !== null;
    }
}
