<?php

namespace App\Commands\Password;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class PasswordsRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PASSWORDS;
    }

    protected function teardownWarning(string $env): array
    {
        return [
            "Vaultwarden will be REMOVED from '{$env}':",
            'Deployment, Services, Ingress, Secrets — and the whole larakube-vault namespace',
            'Every stored password vault goes with it. This cannot be undone.',
        ];
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        // Vaultwarden owns larakube-vault outright, so the namespace delete IS
        // the teardown — nothing else lives there to take down with it.
        return $this->removeNamespace(
            'Removing Vaultwarden namespace...',
            $kubectl,
            $namespace,
        );
    }
}
