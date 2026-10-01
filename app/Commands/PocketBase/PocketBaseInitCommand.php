<?php

namespace App\Commands\PocketBase;

use App\Commands\Data\DataInitCommand;
use App\Enums\ClusterTool;

class PocketBaseInitCommand extends DataInitCommand
{
    protected $signature = 'pocketbase:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for PocketBase (example.com → prefix.example.com). Omit to target/update the default instance}
        {--alias=*    : Additional domain alias(es) to register on this instance\'s Ingress}
        {--admin-email= : Email for the primary admin account}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG_DEFAULT_ON;

    protected $description = 'Deploy a PocketBase stack (Embedded SQLite) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployData();
    }

    protected function resolveEngine(): string
    {
        return 'pocketbase';
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::POCKETBASE;
    }
}
