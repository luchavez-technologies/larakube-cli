<?php

namespace App\Commands\Webmail;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;

abstract class WebmailShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::WEBMAIL;
    }

    protected function rows(?string $host, string $env, string $kubectl, string $instance = ''): array
    {
        if ($instance === '') {
            return [['Webmail UI (Bulwark)', "<fg=gray>not installed — run larakube tool:init --tool=bulwark {$env}</>"]];
        }

        $names = ToolInstance::forInstance(ClusterTool::WEBMAIL, $instance);
        $adminPassword = $this->secretValue($kubectl, $names->namespace(), $names->secret(), 'WEBMAIL_ADMIN_PASSWORD')
            ?? $this->secretValue($kubectl, $names->namespace(), $names->secret(), 'admin-password');

        $rows = [
            [
                'Webmail UI (Bulwark)',
                $host !== null
                    ? "https://{$host}"
                    : "<fg=gray>host not configured — run larakube tool:init --tool=bulwark {$env}</>",
            ],
        ];

        if ($host !== null) {
            $rows[] = [
                'Webmail Admin URL',
                "https://{$host}/admin",
            ];
        }

        if ($adminPassword !== null) {
            $rows[] = [
                'Webmail Admin Password',
                $adminPassword,
            ];
        }

        return $rows;
    }

    protected function credentials(?string $host, string $env, string $instance = ''): ?array
    {
        if ($instance === '') {
            return null;
        }

        $kubectl = Kubectl::forContext($this->resolveToolContext($env, (string) $this->option('context') ?: null))->prefix();
        $names = ToolInstance::forInstance(ClusterTool::WEBMAIL, $instance);
        $adminPassword = $this->secretValue($kubectl, $names->namespace(), $names->secret(), 'WEBMAIL_ADMIN_PASSWORD')
            ?? $this->secretValue($kubectl, $names->namespace(), $names->secret(), 'admin-password');

        return $adminPassword !== null ? ['admin_password' => $adminPassword] : null;
    }
}
