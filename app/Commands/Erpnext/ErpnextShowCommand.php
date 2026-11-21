<?php

namespace App\Commands\Erpnext;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;

class ErpnextShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::ERPNEXT;
    }

    protected function afterTable(?string $host, string $env, string $instance = ''): void
    {
        $creds = $this->credentials($host, $env, $instance);

        if ($creds !== null) {
            $this->newLine();
            $this->line('  <fg=gray>Administrator Login:</>');
            $this->line("  <fg=gray>Username:</>  <fg=blue>{$creds['admin_username']}</>");
            $this->line("  <fg=gray>Password:</>  <fg=yellow>{$creds['admin_password']}</>");
            $this->newLine();
        }
    }

    /** ERPNext's built-in Administrator account, whose password erpnext:init keeps in the tool's Secret. */
    protected function credentials(?string $host, string $env, string $instance = ''): ?array
    {
        $names = match (true) {
            $instance !== '' => ToolInstance::forInstance(ClusterTool::ERPNEXT, $instance),
            $host !== null && $host !== '' => ToolInstance::forHost(ClusterTool::ERPNEXT, $host),
            default => null,
        };
        if ($names === null) {
            return null;
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();

        $password = $this->secretValue($kubectl, $names->namespace(), $names->secret(), 'admin-password');

        return $password === null ? null : [
            'admin_username' => 'Administrator',
            'admin_password' => $password,
        ];
    }
}
