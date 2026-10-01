<?php

namespace App\Commands\Forgejo;

use App\Commands\Git\GitInitCommand;
use App\Enums\ClusterTool;

class ForgejoInitCommand extends GitInitCommand
{
    protected $signature = 'forgejo:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the Forgejo host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for Forgejo (example.com → git.example.com; git.example.com used as-is)}
        {--app-name= : Custom branding name for Forgejo (defaults to Forgejo)}
        {--logo-url= : Custom logo URL for Forgejo}
        {--admin-email= : Email for the Forgejo admin account (defaults to admin@<your domain>)}
        {--no-plex   : Bypass Plex Commons and use local PVC storage instead}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the cluster-wide Forgejo forge, CI/CD runner, and package registry';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployGit();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::FORGEJO;
    }
}
