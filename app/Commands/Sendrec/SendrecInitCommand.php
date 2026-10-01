<?php

namespace App\Commands\Sendrec;

use App\Commands\Record\RecordInitCommand;
use App\Enums\ClusterTool;

class SendrecInitCommand extends RecordInitCommand
{
    protected $signature = 'sendrec:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Sendrec (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--allow-registration : Open public sign-up (needed once to create the first account, then re-run without it)}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Sendrec async video platform stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployRecord();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::SENDREC;
    }
}
