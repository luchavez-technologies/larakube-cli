<?php

namespace App\Commands\Stalwart;

use App\Commands\Mail\MailInitCommand;
use App\Enums\ClusterTool;

class StalwartInitCommand extends MailInitCommand
{
    protected $signature = 'stalwart:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Stalwart (example.com → prefix.example.com)}
        {--alias=*    : Additional domain alias(es) to register on the Ingress}
        {--admin-email= : Primary postmaster / admin email address for Stalwart}
        {--vpn-only  : Restrict the admin UI via NetBird VPN IP whitelisting}
        {--host-port : Bind mail ports directly to the node (default on single-node k3s)}
        {--no-host-port : Skip hostPort — use on managed K8s with a real LoadBalancer}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Stalwart mail server (SMTP/IMAP/JMAP) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployMail();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::STALWART;
    }
}
