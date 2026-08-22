<?php

namespace App\Commands\Dashboard;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class DashboardRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DASHBOARD;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return true;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing CNCF Headlamp Control Plane resources...',
            $this->teardownComponentsCommand($kubectl, $namespace, $this->resolveInstance($kubectl)),
        );
    }
}
