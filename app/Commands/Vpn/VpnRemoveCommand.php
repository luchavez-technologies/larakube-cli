<?php

namespace App\Commands\Vpn;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class VpnRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::VPN;
    }

    protected function teardownWarning(string $env): array
    {
        return [
            "The NetBird VPN stack will be REMOVED from '{$env}':",
            'Deployment, Services, Secrets — and the whole larakube-vpn namespace',
            'Every peer is disconnected, and any tool deployed with --vpn-only becomes unreachable.',
        ];
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing NetBird VPN namespace...',
            "{$kubectl} delete namespace {$namespace} --ignore-not-found",
        );
    }
}
