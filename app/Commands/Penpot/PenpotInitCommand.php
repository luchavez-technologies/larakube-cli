<?php

namespace App\Commands\Penpot;

use App\Commands\Design\DesignInitCommand;
use App\Enums\ClusterTool;

class PenpotInitCommand extends DesignInitCommand
{
    protected $signature = 'penpot:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=      : Target a specific kube-context}
        {--domain=       : Base domain OR full host for Penpot (example.com → prefix.example.com)}
        {--admin-email=  : Primary administrator email for Penpot}
        {--with-exporter : Also deploy the Penpot Exporter (Playwright/Chromium) container for PDF/PNG exports}
        {--vpn-only      : Restrict access via NetBird VPN IP whitelisting}
        {--force         : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Penpot design & prototyping suite into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployDesign();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::PENPOT;
    }
}
