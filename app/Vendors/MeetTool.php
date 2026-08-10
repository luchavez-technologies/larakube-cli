<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;

/** The single vendor backing the MEET category — 'Video Meetings'. Only LiveKit. */
final class MeetTool implements ClusterToolVendor, HasDeploymentBaseName
{
    public function getLabel(): string
    {
        return 'LiveKit';
    }

    public function baseDeploymentName(): string
    {
        return 'meet-livekit';
    }
}
