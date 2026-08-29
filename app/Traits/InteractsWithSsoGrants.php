<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

/**
 * Shared bootstrapping for sso:grant and sso:revoke — resolving the SSO
 * connection, the project the target tool's roles live under (RBAC project
 * for role-gated tools, the tool's own project for open-to-org admin roles),
 * and which grantable tool/role to act on.
 * Deliberately NOT a shared base Command class: this codebase's grant/revoke
 * pairs (see cluster:grant / cluster:revoke) are separate command classes
 * sharing logic via traits, not a common abstract command — kept consistent
 * with that.
 */
trait InteractsWithSsoGrants
{
    use DeploysClusterTool, InteractsWithSso, InteractsWithZitadelApi, RefusesUnshippedTools;

    /**
     * Resolve Zitadel's host and the automation PAT — printing its own
     * error and returning null on the first failure. The PROJECT is NOT
     * resolved here anymore: it depends on the tool (RBAC project vs. the
     * sso-admin tool's own project), so that step moved into
     * resolveSsoProject() after the tool is known.
     *
     * @return array{0: string, 1: string, 2: string}|null [ssoHost, pat, kubectl]
     */
    protected function resolveSsoGrantConnection(string $env, ?string $explicitContext): ?array
    {
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = $this->resolveToolContext($env, $explicitContext);
        $kubectl = $this->ssoKubectl($context);
        $ssoNs = $this->ssoNamespace();

        if (! $this->isSsoInstalled($kubectl, $ssoNs)) {
            $this->laraKubeError('Zitadel is not installed. Run `larakube sso:init` first.');

            return null;
        }

        $ssoHost = $this->resolveSsoHostReadOnly($env, $config, $kubectl);
        if ($ssoHost === null) {
            $this->laraKubeError("No host is configured for Zitadel in '{$env}' — run `sso:init` first.");

            return null;
        }

        $pat = $this->readSsoSecret($kubectl, $ssoNs, 'machine-pat');
        if ($pat === null) {
            $this->laraKubeError('Could not reach Zitadel\'s automation credentials — re-run `larakube sso:init` to recapture them.');

            return null;
        }

        return [$ssoHost, $pat, $kubectl];
    }

    /**
     * Resolve the Zitadel project to grant/revoke on, now that the tool (and,
     * for role-gated tools, the instance) is known. RBAC-gated tools live on
     * their OWN rbacProjectName($instance) — one project per (tool,
     * instance), not one shared project, see ClusterTool::rbacProjectName()'s
     * docblock — open-to-org tools with ssoAdminRoles() live on their OWN
     * project — resolved from the sso-app-<tool> secret sso:wire writes at
     * registration time (so a `--project=` override on the wire is honoured
     * too), falling back to ensuring the default shared project when the
     * secret has no id yet.
     */
    protected function resolveSsoProject(ClusterTool $tool, string $ssoHost, string $pat, string $kubectl, ?string $instance = null): ?string
    {
        if ($tool->requiresRbacGating()) {
            $projectId = $this->zitadelEnsureProject($ssoHost, $pat, $tool->rbacProjectName($instance));
            if ($projectId === null) {
                $this->laraKubeError('Could not reach the '.$tool->rbacProjectName($instance).' Zitadel project.');
            }

            return $projectId;
        }

        if ($tool->ssoAdminRoles() !== []) {
            $projectId = $this->readClusterSecretKey($kubectl, $this->ssoNamespace(), $this->ssoAppSecretName($tool, $instance), 'project-id');
            if ($projectId !== null) {
                return $projectId;
            }

            $projectId = $this->zitadelEnsureProject($ssoHost, $pat, ClusterTool::ssoAdminProjectName());
            if ($projectId === null) {
                $this->laraKubeError('Could not reach the '.ClusterTool::ssoAdminProjectName().' Zitadel project.');
            }

            return $projectId;
        }

        $this->laraKubeError("'{$tool->value}' has no grantable roles.");

        return null;
    }

    /**
     * Only tools with a grantableRoles() schema make sense here — reject
     * anything else up front. The interactive picker (no --tool given)
     * offers EVERY tool that declares roles — role-gated tools (secrets,
     * monitor) and open-to-org admin tools (drive's ocisAdmin) alike — so a
     * new operator can discover them without knowing the --tool flag. A
     * grant for a role the cluster hasn't been wired for yet simply fails
     * against Zitadel with a clear message rather than being hidden.
     */
    protected function resolveGatedTool(string $ssoHost, string $pat, string $kubectl): ?ClusterTool
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
            if (! $tool->requiresRbacGating() && $tool->ssoAdminRoles() === []) {
                $this->laraKubeError("'{$tool->value}' has no role-gated access to grant — every authenticated user already gets in.");

                return null;
            }

            return $tool;
        }

        $gated = array_values(array_filter(
            ClusterTool::shippedCases(),
            fn (ClusterTool $t) => $t->grantableRoles() !== [],
        ));
        if ($gated === []) {
            $this->laraKubeError('No tools define grantable roles.');

            return null;
        }

        $options = [];
        foreach ($gated as $t) {
            $options[$t->value] = $t->getLabel();
        }

        return ClusterTool::from(select(label: 'Which tool?', options: $options, default: array_key_first($options)));
    }

    /**
     * Resolve a partner org by name, or offer a picker when --org= is
     * omitted — the org-level counterpart to resolveGatedTool(). Errors
     * with its own message and returns null when neither an explicit name
     * nor any org exists to pick from.
     */
    protected function resolveOrg(string $ssoHost, string $pat): ?string
    {
        $orgOption = (string) ($this->option('org') ?: '');
        if ($orgOption !== '') {
            $orgId = $this->zitadelFindOrgByName($ssoHost, $pat, $orgOption);
            if ($orgId === null) {
                $this->laraKubeError("No Zitadel organization named '{$orgOption}' — run \`larakube sso:org\` first.");
            }

            return $orgId;
        }

        $orgs = $this->zitadelListOrgs($ssoHost, $pat);
        if ($orgs === []) {
            $this->laraKubeError('No Zitadel organizations exist yet — run `larakube sso:org` first.');

            return null;
        }

        return select(label: 'Which org?', options: $orgs, default: array_key_first($orgs));
    }

    protected function resolveRole(ClusterTool $tool): ?string
    {
        $roles = $tool->grantableRoles();

        $roleKey = (string) ($this->option('role') ?: '');
        if ($roleKey !== '') {
            if (! array_key_exists($roleKey, $roles)) {
                $this->laraKubeError("'{$roleKey}' isn't a role {$tool->getLabel()} defines. Available: ".implode(', ', array_keys($roles)));

                return null;
            }

            return $roleKey;
        }

        return select(label: 'Which role?', options: $roles, default: array_key_first($roles));
    }

    /**
     * Ensure a Zitadel user exists by email, offering to create & invite them if missing.
     */
    protected function ensureZitadelUserExists(string $ssoHost, string $pat, string $email, ?string $name = null, ?string $password = null): ?string
    {
        $userId = $this->zitadelFindUserByEmail($ssoHost, $pat, $email);
        if ($userId !== null) {
            return $userId;
        }

        $localPart = explode('@', $email)[0];
        $displayName = $name ?: $localPart;
        $initialPassword = $password ?: Str::password(24);

        $createdId = $this->zitadelCreateUser($ssoHost, $pat, $email, $displayName, $initialPassword);
        if ($createdId !== null) {
            $this->laraKubeInfo("✅ Created new Zitadel SSO user account for '{$email}'.");
            $this->line("  <fg=gray>Initial Password:</> <fg=yellow>{$initialPassword}</>");
            $this->newLine();
        }

        return $createdId;
    }
}
