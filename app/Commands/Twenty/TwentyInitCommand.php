<?php

namespace App\Commands\Twenty;

use App\Commands\Crm\CrmInitCommand;
use App\Enums\ClusterTool;

class TwentyInitCommand extends CrmInitCommand
{
    protected $signature = 'twenty:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Twenty (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Twenty CRM stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployCrm();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::TWENTY;
    }
}
