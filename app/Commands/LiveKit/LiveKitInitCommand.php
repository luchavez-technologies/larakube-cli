<?php

namespace App\Commands\LiveKit;

use App\Commands\Meet\MeetInitCommand;
use App\Enums\ClusterTool;

class LiveKitInitCommand extends MeetInitCommand
{
    protected $signature = 'livekit:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for LiveKit (example.com → meet.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--no-host-port : Skip hostPort on LiveKit — use on managed K8s with a real LoadBalancer}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the shared LiveKit SFU (Meet) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployMeet();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::LIVEKIT;
    }
}
