<?php

namespace App\Commands\Outline;

use App\Commands\Notes\NotesInitCommand;
use App\Enums\ClusterTool;

class OutlineInitCommand extends NotesInitCommand
{
    protected $signature = 'outline:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Outline (example.com → prefix.example.com). Omit to target/update the default instance; pass a different host to deploy an ADDITIONAL instance there — the host you give IS its identity}
        {--alias=*    : Additional domain alias(es) to register on this instance\'s Ingress}
        {--admin-email= : Primary administrator email for Outline}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Outline wiki / knowledge base stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployNotes();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::OUTLINE;
    }
}
