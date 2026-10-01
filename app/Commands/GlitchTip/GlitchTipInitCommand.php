<?php

namespace App\Commands\GlitchTip;

use App\Commands\Errors\ErrorsInitCommand;
use App\Enums\ClusterTool;

class GlitchTipInitCommand extends ErrorsInitCommand
{
    protected $signature = 'glitchtip:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the GlitchTip host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for GlitchTip (example.com → errors.example.com; errors.example.com used as-is)}
        {--app-name= : Custom branding name for GlitchTip (defaults to Error Tracking)}
        {--logo-url= : Custom logo URL for GlitchTip}
        {--admin-email= : Primary administrator email for GlitchTip}
        {--no-plex   : Bypass Plex Commons and deploy dedicated database/cache pods instead}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the cluster-wide GlitchTip error tracking stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployErrors();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::GLITCHTIP;
    }
}
