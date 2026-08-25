<?php

namespace App\Commands\Sso;

use App\Enums\ClusterTool;
use App\Traits\InteractsWithSsoGrants;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

/**
 * Give a partner org (onboarded via sso:org) scoped access to one of the
 * operator's own Zitadel projects — Zitadel's real cross-org sharing
 * primitive (a Project Grant), not a role-grant workaround. Once this
 * exists, the existing per-user `sso:grant` works unmodified against the
 * partner org's own users: Zitadel resolves the ProjectGrant internally.
 *
 * --tool= (picking a tool the same way sso:grant/sso:revoke already do,
 * resolving its own per-instance project via rbacProjectName()) is the
 * primary path since the 2026-08-20 per-tool-project RBAC redesign — most
 * tools no longer share one project, so a bare --project= now requires
 * already knowing the exact, sometimes-instance-suffixed Zitadel project
 * name. --project= stays as an explicit escape hatch for the genuinely
 * open "LaraKube Shared Tools" project (no grantable roles of its own) or
 * any custom project — when given, it skips tool/instance resolution
 * entirely, unchanged from before this redesign.
 */
class SsoOrgGrantCommand extends Command
{
    use InteractsWithSsoGrants, InteractsWithZitadelApi, LaraKubeOutput;

    protected $signature = 'sso:org-grant
        {environment=local : Environment whose Zitadel to target}
        {--context= : Target a specific kube-context}
        {--org=     : The partner org\'s domain/name, e.g. partner.example (must exist — run sso:org first). Omit to pick interactively.}
        {--tool=    : The tool to share (same tools sso:grant offers). Omit to pick interactively. Mutually exclusive with --project=.}
        {--domain=  : The instance to target for a multi-instance tool (e.g. --domain=blog.example.com) — same meaning as sso:grant}
        {--project= : Raw Zitadel project name — escape hatch for "LaraKube Shared Tools" or any custom project. Skips --tool=/--domain= entirely when given.}
        {--role=*   : Specific role key(s) to include (default: every role currently defined on the project)}';

    protected $description = 'Grant a partner org (sso:org) scoped access to a tool — or, via --project=, any raw Zitadel project';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $connection = $this->resolveSsoGrantConnection($env, $this->option('context'));
        if ($connection === null) {
            return 1;
        }
        [$ssoHost, $pat, $kubectl] = $connection;

        $grantedOrgId = $this->resolveOrg($ssoHost, $pat);
        if ($grantedOrgId === null) {
            return 1;
        }
        $orgOption = (string) $this->option('org');

        $projectOption = (string) ($this->option('project') ?: '');
        $instance = null;

        if ($projectOption !== '') {
            $projectName = $projectOption;
        } else {
            $tool = $this->resolveGatedTool($ssoHost, $pat, $kubectl);
            if ($tool === null) {
                return 1;
            }

            $instance = $this->resolveInstanceForTool($tool, $kubectl, (string) ($this->option('domain') ?: ''));
            if ($instance === false) {
                return 1;
            }

            $projectName = $tool->requiresRbacGating() ? $tool->rbacProjectName($instance) : ClusterTool::ssoAdminProjectName();

            // sso:wire only ever installs flattenOcisRoles into ITS OWN
            // (default) org — the project/roles it configures are shared,
            // but this Action is not: Zitadel Actions/Flows are scoped to
            // one org each. Without installing it here too, a granted-org
            // user's token never carries the ocisRoles claim, and oCIS
            // (PROXY_ROLE_ASSIGNMENT_DRIVER=oidc, no fallback claim) denies
            // their login outright regardless of any role grant — confirmed
            // live 2026-08-24. See zitadelEnsureOcisRolesAction()'s docblock.
            if ($tool->ssoAdminRoles() !== []) {
                $ok = true;
                $this->withSpin("Configuring {$tool->getLabel()}'s admin-role claims for this org...", function () use ($ssoHost, $pat, $grantedOrgId, &$ok): void {
                    $ok = $this->zitadelEnsureOcisRolesAction($ssoHost, $pat, $grantedOrgId);
                });

                if (! $ok) {
                    $this->laraKubeError("Could not install {$tool->getLabel()}'s admin-role claim Action in this org. Check the Zitadel API connection.");

                    return 1;
                }
            }
        }

        $projectId = $this->zitadelEnsureProject($ssoHost, $pat, $projectName);
        if ($projectId === null) {
            $this->laraKubeError("Failed to resolve the '{$projectName}' project.");

            return 1;
        }

        $roleKeys = $this->option('role');
        if ($roleKeys === []) {
            $roleKeys = $this->zitadelListProjectRoleKeys($ssoHost, $pat, $projectId);
        }

        if ($roleKeys === []) {
            $this->laraKubeError("'{$projectName}' has no roles defined yet to grant — wire a tool onto it first, or pass --role= explicitly.");

            return 1;
        }

        $grantId = $this->zitadelEnsureProjectGrant($ssoHost, $pat, $projectId, $grantedOrgId, $roleKeys);
        if ($grantId === null) {
            $this->laraKubeError('Failed to create/update the project grant. Check the Zitadel API connection.');

            return 1;
        }

        $domainFlag = $instance !== null ? " --domain={$instance}" : '';

        $this->newLine();
        $this->laraKubeInfo("✅ '{$orgOption}' now has scoped access to '{$projectName}'.");
        $this->newLine();
        $this->line('  <fg=gray>Roles:</> <fg=blue>'.implode(', ', $roleKeys).'</>');
        $this->newLine();
        $this->line('  <fg=gray>Grant a specific role to one of their users:</>');
        $this->line("  <fg=blue>larakube sso:grant {$env} --tool=<tool>{$domainFlag} --email=<user@{$orgOption}> --role=<role></>");
        $this->newLine();
        $this->line('  <fg=gray>Note:</> this relies on Zitadel resolving zitadelGrantRole()\'s UserGrant against the');
        $this->line('  ProjectGrant automatically for a granted-org user — verify this live before relying on it.');
        $this->newLine();

        return 0;
    }
}
