<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the WEBMAIL category — 'Webmail UI'. Only Bulwark. 1:1 bound to the one Stalwart. */
final class WebmailTool implements ClusterToolVendor, HasDeploymentBaseName, HasVpnWiring, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Bulwark';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '')
            ? 'webmail-vpn-only'
            : ToolInstance::forInstance(ClusterTool::WEBMAIL, $instance)->name('vpn-only');

        return [
            'name' => $name,
            'namespace' => ClusterTool::WEBMAIL->namespace(),
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'webmail-bulwark';
    }

    public function canonicalComponentName(): string
    {
        return 'bulwark';
    }

    /**
     * One PRIMARY component — every resource bulwark.blade.php declares, so
     * teardown() can't drift from what is actually deployed.
     *
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        // ClusterTool::components() strips the category for a migrated tool;
        // these nested resource names have to follow the same rule. Composed
        // here rather than read back from ToolInstance, which derives every
        // name FROM this list and would recurse.
        $canonical = fn (string $n) => ClusterTool::WEBMAIL->withoutCategory($n);
        $deployment = $canonical($name('webmail-bulwark'));

        return [
            new ClusterToolComponentData(
                key: 'app',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $deployment,
                resources: [
                    ['kind' => 'service', 'name' => $deployment],
                    ['kind' => 'ingress', 'name' => $deployment],
                    ['kind' => 'secret', 'name' => $canonical($name('webmail-bulwark-secrets'))],
                    // Not a backup target: Bulwark regenerates its admin
                    // config and settings-sync on the next webmail:init.
                    ['kind' => 'pvc', 'name' => $canonical($name('webmail-bulwark-storage'))],
                ],
            ),
        ];
    }
}
