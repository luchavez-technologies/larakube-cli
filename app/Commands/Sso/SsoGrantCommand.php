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
        {--domain= : The instance to target for a multi-instance tool (e.g. --domain=blog.example.com) — required whenever that tool has no single unnamed instance, e.g. notes}
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

        // rbacProjectName() is per (tool, instance) now — omitting --domain=
        // for a tool with no single unnamed instance (e.g. notes, whose only
        // real instance is already named) would silently target a DIFFERENT,
        // empty project instead of erroring, so auto-resolve the one
        // unambiguous case and refuse the ambiguous one rather than guess.
        $domainOption = (string) ($this->option('domain') ?: '');
        $instance = null;
        if ($domainOption !== '') {
            $instance = $this->resolveInstanceForDomain($kubectl, $tool, $this->normalizeTargetHost($domainOption));
        } elseif ($tool->supportsMultipleInstances()) {
            $named = array_values(array_unique(array_filter(
                $this->getToolInstances($kubectl, $tool),
                fn (?string $i) => $i !== null && $i !== '' && $i !== 'main',
            )));
            if (count($named) === 1) {
                $instance = $named[0];
            } elseif (count($named) > 1) {
                $this->laraKubeError("'{$tool->value}' has multiple instances — pass --domain= to pick one.");

                return 1;
            }
        }

        $projectId = $this->resolveSsoProject($tool, $ssoHost, $pat, $kubectl, $instance);
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

        $userId = $this->ensureZitadelUserExists($ssoHost, $pat, $email);
        if ($userId === null) {
            $this->laraKubeError("Failed to resolve or create Zitadel user for '{$email}'.");

            return 1;
        }

        if (! $this->zitadelGrantRole($ssoHost, $pat, $userId, $projectId, $roleKey)) {
            $this->laraKubeError("Failed to grant '{$roleKey}' for {$email}.");

            return 1;
        }

        $grant = $this->zitadelFindUserGrant($ssoHost, $pat, $userId, $projectId);
        $current = $grant['roleKeys'] ?? [];

        $projectName = $tool->requiresRbacGating() ? $tool->rbacProjectName($instance) : ClusterTool::ssoAdminProjectName();

        $this->laraKubeInfo("✅ Granted '{$roleKey}' to {$email}.");
        $this->newLine();
        $this->line("  <fg=gray>{$email}'s roles on {$projectName}:</> <fg=blue>".implode(', ', $current).'</>');
        $this->newLine();

        return 0;
    }
}
