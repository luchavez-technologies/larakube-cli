<?php

namespace App\Commands\Sso;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class SsoRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SSO;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return $this->deploymentExists($kubectl, $namespace, 'sso-zitadel-db');
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
        return $this->removeResources(
            'Removing Zitadel namespace...',
            "{$kubectl} delete namespace {$namespace} --ignore-not-found",
        );
    }
}
