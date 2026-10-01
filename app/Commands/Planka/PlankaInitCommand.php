<?php

namespace App\Commands\Planka;

use App\Commands\Tasks\TasksInitCommand;
use App\Enums\ClusterTool;

class PlankaInitCommand extends TasksInitCommand
{
    protected $signature = 'planka:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Planka (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Planka task management stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployTasks();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::PLANKA;
    }
}
