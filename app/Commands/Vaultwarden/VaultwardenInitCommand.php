<?php

namespace App\Commands\Vaultwarden;

use App\Commands\Password\PasswordsInitCommand;
use App\Enums\ClusterTool;

class VaultwardenInitCommand extends PasswordsInitCommand
{
    protected $signature = 'vaultwarden:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the Vaultwarden host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for Vaultwarden (example.com → vault.example.com; vault.example.com used as-is)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the cluster-wide Vaultwarden team password manager into larakube-vault';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployVault();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::VAULTWARDEN;
    }
}
