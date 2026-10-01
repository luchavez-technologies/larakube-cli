<?php

namespace App\Commands\Documenso;

use App\Commands\Sign\SignInitCommand;
use App\Enums\ClusterTool;

class DocumensoInitCommand extends SignInitCommand
{
    protected $signature = 'documenso:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Documenso (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Documenso electronic signature stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySign();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::DOCUMENSO;
    }
}
