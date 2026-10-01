<?php

namespace App\Commands\Sso;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;

class SsoRemoveCommand extends AbstractToolRemoveCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'sso:remove' is deprecated. Please use 'zitadel:remove' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::SSO;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::first($kubectl, ClusterTool::SSO);

        return $names !== null && $this->deploymentExists($kubectl, $namespace, $names->deployment('db'));
    }

    protected function teardownWarning(string $env): array
    {
        return array_merge(parent::teardownWarning($env), [
            'Every tool wired to this Zitadel will lose SSO login — run sso:wire --remove first if you want them left clean.',
        ]);
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        // Whole dedicated namespace, matching Vaultwarden/OpenBao/NetBird —
        // nothing else lives in larakube-sso.
        return $this->removeNamespace(
            'Removing Zitadel namespace...',
            $kubectl,
            $namespace,
        );
    }
}
