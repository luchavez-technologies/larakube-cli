<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterTool;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the MEET category — 'Video Meetings'. Only LiveKit. */
final class MeetTool implements ClusterToolVendor, HasDeploymentBaseName, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'LiveKit';
    }

    public function baseDeploymentName(): string
    {
        return 'meet-livekit';
    }

    public function canonicalComponentName(): string
    {
        return 'livekit';
    }

    /**
     * lk-jwt is LiveKit's token service — meet/lk-jwt.blade.php names it from
     * the same instance as livekit itself, so it belongs to this tool rather
     * than being an unowned Deployment forDeployment() cannot map.
     *
     * Every resource the two manifests declare is listed, so teardown() can
     * never drift from what is actually deployed.
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
        $canonical = fn (string $n) => ClusterTool::MEET->withoutCategory($n);
        $livekit = $canonical($name('meet-livekit'));
        $bridge = $canonical($name('meet-lk-jwt'));

        return [
            new ClusterToolComponentData(
                key: 'livekit',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $livekit,
                resources: [
                    ['kind' => 'service', 'name' => $livekit],
                    ['kind' => 'service', 'name' => $canonical($name('meet-livekit-rtc'))],
                    ['kind' => 'ingress', 'name' => $livekit],
                    ['kind' => 'secret', 'name' => $canonical($name('meet-livekit-config'))],
                    // The consumer key registry. It goes with the SFU: the
                    // credentials are meaningless without the server that
                    // honours them.
                    ['kind' => 'secret', 'name' => $canonical($name('meet-livekit-secrets'))],
                ],
            ),
            new ClusterToolComponentData(
                key: 'lk-jwt',
                role: ClusterToolComponentRole::AUTH,
                deployment: $bridge,
                resources: [
                    ['kind' => 'service', 'name' => $bridge],
                    ['kind' => 'middleware', 'name' => $canonical($name('meet-lk-jwt-stripprefix'))],
                ],
            ),
        ];
    }
}
