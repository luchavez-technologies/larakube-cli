<?php

namespace App\Commands\Ocis;

use App\Commands\Drive\DriveInitCommand;
use App\Enums\ClusterTool;

class OcisInitCommand extends DriveInitCommand
{
    protected $signature = 'ocis:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for oCIS (example.com → prefix.example.com)}
        {--no-plex   : Bypass Plex Commons (SQLite/Local PVC instead of Postgres/S3)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--extensions= : Comma-separated web extensions to pre-install (e.g. "drawio,excalidraw")}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the oCIS cloud storage and sync stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployDrive();
    }

    protected function resolveEngine(): string
    {
        return 'ocis';
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::OCIS;
    }
}
