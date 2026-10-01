<?php

namespace App\Commands\Yopass;

use App\Commands\Paste\PasteInitCommand;
use App\Enums\ClusterTool;

class YopassInitCommand extends PasteInitCommand
{
    protected $signature = 'yopass:init
        {environment? : Environment this install targets — "local" (default) or a cloud env.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Yopass (example.com → paste.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting — WARNING: this tool exists to receive a paste from an external, unauthenticated partner; --vpn-only blocks exactly that. Only use it for an internal-scratchpad-only install.}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy Yopass (secure, one-time-read paste sharing) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployPaste();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::YOPASS;
    }
}
