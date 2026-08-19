<?php

namespace App\Commands\Sso;

use App\Traits\InteractsWithSsoGrants;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

/**
 * Give a partner org (onboarded via sso:org) scoped access to one of the
 * operator's own Zitadel projects — Zitadel's real cross-org sharing
 * primitive (a Project Grant), not a role-grant workaround. Once this
 * exists, the existing per-user `sso:grant` works unmodified against the
 * partner org's own users: Zitadel resolves the ProjectGrant internally.
 */
class SsoOrgGrantCommand extends Command
{
    use InteractsWithSsoGrants, LaraKubeOutput;

    protected $signature = 'sso:org-grant
        {environment=local : Environment whose Zitadel to target}
        {--context= : Target a specific kube-context}
        {--org=     : The partner org\'s domain/name, e.g. partner.example (must exist — run sso:org first)}
        {--project= : Existing Zitadel project to grant (default: "LaraKube Shared Tools")}
        {--role=*   : Specific role key(s) to include (default: every role currently defined on the project)}';

    protected $description = 'Grant a partner org (sso:org) scoped access to one of the operator\'s Zitadel projects';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $connection = $this->resolveSsoGrantConnection($env, $this->option('context'));
        if ($connection === null) {
            return 1;
        }
        [$ssoHost, $pat] = $connection;

        $orgOption = (string) $this->option('org');
        if ($orgOption === '') {
            $this->laraKubeError('--org is required — the partner org\'s domain/name (see `larakube sso:org`).');

            return 1;
        }

        $grantedOrgId = $this->zitadelFindOrgByName($ssoHost, $pat, $orgOption);
        if ($grantedOrgId === null) {
            $this->laraKubeError("No Zitadel organization named '{$orgOption}' — run \`larakube sso:org {$env} --zone={$orgOption} --cloudflare-token=…\` first.");

            return 1;
        }

        $projectName = (string) ($this->option('project') ?: 'LaraKube Shared Tools');
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

        $this->newLine();
        $this->laraKubeInfo("✅ '{$orgOption}' now has scoped access to '{$projectName}'.");
        $this->newLine();
        $this->line('  <fg=gray>Roles:</> <fg=blue>'.implode(', ', $roleKeys).'</>');
        $this->newLine();
        $this->line('  <fg=gray>Grant a specific role to one of their users:</>');
        $this->line("  <fg=blue>larakube sso:grant {$env} --tool=<tool> --email=<user@{$orgOption}> --role=<role></>");
        $this->newLine();
        $this->line("  <fg=gray>Note:</> this relies on Zitadel resolving zitadelGrantRole()'s UserGrant against the");
        $this->line('  ProjectGrant automatically for a granted-org user — verify this live before relying on it.');
        $this->newLine();

        return 0;
    }
}
