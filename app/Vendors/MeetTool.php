<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
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

    /**
     * lk-jwt is LiveKit's token service — meet/lk-jwt.blade.php threads the
     * same instance suffix as livekit itself, so it belongs to this tool
     * rather than being an unowned Deployment forDeployment() cannot map.
     *
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'livekit',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('meet-livekit'),
            ),
            new ClusterToolComponentData(
                key: 'lk-jwt',
                role: ClusterToolComponentRole::AUTH,
                deployment: $name('meet-lk-jwt'),
            ),
        ];
    }
}
