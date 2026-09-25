<?php

namespace App\Tools;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasImages;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Tools\Concerns\PinsImages;

/** Windmill, one of the FLOW engines. */
final class Windmill implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasImages, HasSmtpWiring, HasVpnWiring
{
    use PinsImages;

    public const string ENGINE = 'windmill';

    public function getLabel(): string
    {
        return 'Windmill';
    }

    public function images(): array
    {
        return [
            'windmill' => 'ghcr.io/windmill-labs/windmill:1.770.0',
            'lsp' => 'ghcr.io/windmill-labs/windmill-lsp:1.134.1',
            // Only for --no-plex, which bundles its own database.
            'postgres' => 'postgres:15-alpine',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'flow-windmill';
    }

    public function canonicalComponentName(): string
    {
        return 'windmill';
    }

    public function commonsDatabaseList(): array
    {
        return ['windmill'];
    }

    public function canonicalDatabaseList(): array
    {
        return ['windmill'];
    }

    /** Shared by both engines: an instance is a host, whichever engine serves it. */
    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        return [
            'name' => ($instance === null || $instance === '')
                ? 'flow-vpn-only'
                : ToolInstance::forInstance(ClusterTool::FLOW, $instance, self::ENGINE)->name('vpn-only'),
            'namespace' => ClusterTool::FLOW->namespace(),
        ];
    }

    /** Windmill has no SMTP schema yet, so mail:wire refuses it rather than patch the wrong Deployment. */
    public function smtpEnv(?string $instance = null): ?array
    {
        return null;
    }
}
