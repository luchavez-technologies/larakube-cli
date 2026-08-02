<?php

namespace App\Commands\Sso;

use App\Enums\ClusterTool;
use App\Traits\InteractsWithSsoGrants;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class SsoGrantCommand extends Command
{
    use InteractsWithSsoGrants, LaraKubeOutput;

    protected $signature = 'sso:grant
        {environment=local : Environment whose Zitadel to target}
        {--email= : Email of the Zitadel user to grant a role to}
        {--tool= : The grantable tool (secrets, monitor, drive)}
        {--role= : The role key to grant}
        {--context= : Target a specific kube-context}';

    protected $description = 'Grant a tool\'s Zitadel role to a user — role-gated tools (secrets, monitor) or open-to-org admin roles (drive\'s ocisAdmin) — see sso:revoke to take one away';

    public function handle(): int
    {
        $this->renderHeader();

        $connection = $this->resolveSsoGrantConnection((string) $this->argument('environment'), $this->option('context'));
        if ($connection === null) {
            return 1;
        }
        [$ssoHost, $pat, $kubectl] = $connection;

        $tool = $this->resolveGatedTool($ssoHost, $pat, $kubectl);
        if ($tool === null) {
            return 1;
        }

        $projectId = $this->resolveSsoProject($tool, $ssoHost, $pat, $kubectl);
        if ($projectId === null) {
            return 1;
        }

        $roleKey = $this->resolveRole($tool);
        if ($roleKey === null) {
            return 1;
        }

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

        if (! $this->zitadelGrantRole($ssoHost, $pat, $userId, $projectId, $roleKey)) {
            $this->laraKubeError("Failed to grant '{$roleKey}' for {$email}.");

            return 1;
        }

        $grant = $this->zitadelFindUserGrant($ssoHost, $pat, $userId, $projectId);
        $current = $grant['roleKeys'] ?? [];

        $projectName = $tool->requiresRbacGating() ? ClusterTool::rbacProjectName() : ClusterTool::ssoAdminProjectName();

        $this->laraKubeInfo("✅ Granted '{$roleKey}' to {$email}.");
        $this->newLine();
        $this->line("  <fg=gray>{$email}'s roles on {$projectName}:</> <fg=blue>".implode(', ', $current).'</>');
        $this->newLine();

        return 0;
    }
}
