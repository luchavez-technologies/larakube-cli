<?php

namespace App\Commands\Teable;

use App\Commands\Sheet\SheetsInitCommand;
use App\Enums\ClusterTool;

class TeableInitCommand extends SheetsInitCommand
{
    protected $signature = 'teable:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Teable (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy Teable (spreadsheet database) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySheet();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::TEABLE;
    }
}
