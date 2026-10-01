<?php

namespace App\Commands\Matrix;

use App\Commands\Chat\ChatInitCommand;
use App\Enums\ClusterTool;

class MatrixInitCommand extends ChatInitCommand
{
    protected $signature = 'matrix:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Matrix (example.com → prefix.example.com)}
        {--app-name= : Custom branding name for the Element Web UI (defaults to Matrix)}
        {--logo-url= : Custom logo URL for the Element Web UI}
        {--no-plex   : Bypass Plex Commons and bundle dedicated storage}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--no-host-port : Skip hostPort on Coturn — use on managed K8s with a real LoadBalancer}
        {--media-retention=30d : Keep media local for this long after last access; older files live only in S3 (s, h, d, m, y)}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Matrix / Synapse chat stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployChat();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::MATRIX;
    }
}
