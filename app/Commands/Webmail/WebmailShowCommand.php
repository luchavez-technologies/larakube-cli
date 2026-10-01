<?php

namespace App\Commands\Webmail;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;

class WebmailShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'webmail:show' is deprecated. Please use 'bulwark:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::WEBMAIL;
    }

    protected function rows(?string $host, string $env, string $kubectl, string $instance = ''): array
    {
        if ($instance === '') {
            return [['Webmail UI (Bulwark)', "<fg=gray>not installed — run larakube bulwark:init {$env}</>"]];
        }

        $names = ToolInstance::forInstance(ClusterTool::WEBMAIL, $instance);
        $adminPassword = $this->secretValue($kubectl, $names->namespace(), $names->secret(), 'WEBMAIL_ADMIN_PASSWORD')
            ?? $this->secretValue($kubectl, $names->namespace(), $names->secret(), 'admin-password');

        $rows = [
            [
                'Webmail UI (Bulwark)',
                $host !== null
                    ? "https://{$host}"
                    : "<fg=gray>host not configured — run larakube bulwark:init {$env}</>",
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
}
