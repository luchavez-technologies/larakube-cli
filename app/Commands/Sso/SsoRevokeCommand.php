<?php

namespace App\Commands\Sso;

use App\Enums\ClusterTool;
use App\Traits\InteractsWithSsoGrants;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class SsoRevokeCommand extends Command
{
    use InteractsWithSsoGrants, LaraKubeOutput;

    protected $signature = 'sso:revoke
        {environment=local : Environment whose Zitadel to target}
        {--email= : Email of the Zitadel user to revoke access from}
        {--role= : Revoke exactly this role key (skips the discovery picker)}
        {--context= : Target a specific kube-context}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Revoke a user\'s role-gated access — email-first: shows everything they currently hold so you can pick what to pull, all in one pass (built for the "this account is compromised" case)';

    public function handle(): int
    {
        $this->renderHeader();

        $connection = $this->resolveSsoGrantConnection((string) $this->argument('environment'), $this->option('context'));
        if ($connection === null) {
            return 1;
        }
        [$ssoHost, $pat, $kubectl] = $connection;

        $email = trim((string) ($this->option('email') ?: text('Email of the Zitadel user')));
        if ($email === '') {
            $this->laraKubeError('An email is required.');

            return 1;
        }

        $userId = $this->zitadelFindUserByEmail($ssoHost, $pat, $email);
        if ($userId === null) {
            $this->laraKubeError("No Zitadel user found for '{$email}'.");

            return 1;
        }

        // The fast/scripted path: --role names the exact role key, and the
        // owning tool (and therefore the project it lives under) resolves
        // from the key itself — no --tool needed, grantableRoles() keys are
        // globally unique by construction. Every role carries its owning
        // project with it, because role-gated roles (secrets, monitor) live on
        // the RBAC project while open-to-org admin roles (drive's ocisAdmin)
        // live on the shared project.
        $explicitRole = (string) ($this->option('role') ?: '');
        $toRevoke = null;

        if ($explicitRole !== '') {
            $tool = ClusterTool::forGrantableRoleKey($explicitRole);
            if ($tool === null) {
                $this->laraKubeError("'{$explicitRole}' isn't a role any wired tool defines.");

                return 1;
            }
            $projectId = $this->resolveSsoProject($tool, $ssoHost, $pat, $kubectl);
            if ($projectId === null) {
                return 1;
            }
            $projectName = $tool->requiresRbacGating() ? ClusterTool::rbacProjectName() : ClusterTool::ssoAdminProjectName();
            $toRevoke = [['role' => $explicitRole, 'projectId' => $projectId, 'projectName' => $projectName]];
        } else {
            $toRevoke = $this->resolveRolesFromCurrentAccess($ssoHost, $pat, $userId, $email);
            if ($toRevoke === null) {
                return 1;
            }
            if ($toRevoke === []) {
                return 0;
            }
        }

        $list = implode(', ', array_column($toRevoke, 'role'));
        if (! $this->option('force') && ! confirm("Revoke [{$list}] from {$email}?", false)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $involvedProjects = [];
        foreach ($toRevoke as $item) {
            if (! $this->zitadelRevokeRole($ssoHost, $pat, $userId, $item['projectId'], $item['role'])) {
                $this->laraKubeError("Failed to revoke '{$item['role']}' from {$email}.");

                return 1;
            }
            $involvedProjects[$item['projectName']] = $item['projectId'];
        }

        $this->laraKubeInfo("✅ Revoked [{$list}] from {$email}.");
        $this->newLine();
        foreach ($involvedProjects as $name => $id) {
            $grant = $this->zitadelFindUserGrant($ssoHost, $pat, $userId, $id);
            $current = $grant['roleKeys'] ?? [];
            $this->line($current === []
                ? "  <fg=gray>{$email} now holds no roles on {$name}.</>"
                : "  <fg=gray>{$email}'s remaining roles on {$name}:</> <fg=blue>".implode(', ', $current).'</>');
        }
        $this->newLine();

        return 0;
    }

    /**
     * The discovery path: show every role-gated access this user currently
     * holds and let them multiselect which to pull. This is the point of the
     * command: for a compromised account, "type the email, see everything,
     * strip what you want" beats having to already know which tool+role to
     * name and running this once per role. default: [] under non-interactive/
     * test mode means nothing gets revoked without an explicit selection — no
     * accidental full wipe from an unattended run.
     *
     * Roles live on TWO projects and both are inspected: role-gated roles
     * (secrets, monitor) on the RBAC project, open-to-org admin roles
     * (drive's ocisAdmin) on the shared project. Each returned item carries
     * the project its role lives under, because a role key alone can't tell
     * the revoke path where to look — grantableRoles() keys are globally
     * unique, but the owning project isn't derivable from the key.
     *
     * @return list<array{role: string, projectId: string, projectName: string}>|null
     *                                                                                null only when neither project could be reached at all —
     *                                                                                a connectivity failure, not "nothing to revoke".
     */
    protected function resolveRolesFromCurrentAccess(string $ssoHost, string $pat, string $userId, string $email): ?array
    {
        $projects = [];
        $rbacId = $this->zitadelEnsureProject($ssoHost, $pat, ClusterTool::rbacProjectName());
        if ($rbacId !== null) {
            $projects[ClusterTool::rbacProjectName()] = $rbacId;
        }
        $sharedId = $this->zitadelEnsureProject($ssoHost, $pat, ClusterTool::ssoAdminProjectName());
        if ($sharedId !== null) {
            $projects[ClusterTool::ssoAdminProjectName()] = $sharedId;
        }

        if ($projects === []) {
            $this->laraKubeError('Could not reach the Zitadel projects holding role-gated access.');

            return null;
        }

        $options = [];
        $found = []; // role key => owning ['projectName', 'projectId']
        foreach ($projects as $projectName => $projectId) {
            $grant = $this->zitadelFindUserGrant($ssoHost, $pat, $userId, $projectId);
            foreach ($grant['roleKeys'] ?? [] as $roleKey) {
                $found[$roleKey] = ['projectName' => $projectName, 'projectId' => $projectId];
                $tool = ClusterTool::forGrantableRoleKey($roleKey);
                $toolLabel = $tool?->getLabel() ?? 'Unknown tool';
                $roleLabel = $tool?->grantableRoles()[$roleKey] ?? $roleKey;
                $options[$roleKey] = "{$toolLabel} — {$roleLabel}";
            }
        }

        if ($options === []) {
            $this->laraKubeInfo("{$email} holds no role-gated access — nothing to revoke.");

            return [];
        }

        $selected = multiselect(
            label: "{$email}'s current access — select what to revoke",
            options: $options,
            default: [],
        );

        return array_map(
            fn (string $roleKey) => [
                'role' => $roleKey,
                'projectId' => $found[$roleKey]['projectId'],
                'projectName' => $found[$roleKey]['projectName'],
            ],
            $selected,
        );
    }
}
