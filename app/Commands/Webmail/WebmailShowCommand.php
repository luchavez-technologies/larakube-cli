<?php

namespace App\Commands\Webmail;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class WebmailShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::WEBMAIL;
    }

    protected function rows(?string $host, string $env, string $kubectl, string $instance = ''): array
    {
        $adminPassword = $this->secretValue($kubectl, 'larakube-shared', 'webmail-secrets', 'WEBMAIL_ADMIN_PASSWORD')
            ?? $this->secretValue($kubectl, 'larakube-shared', 'webmail-secrets', 'admin-password');

        $rows = [
            [
                'Webmail UI (Bulwark)',
                $host !== null
                    ? "https://{$host}"
                    : "<fg=gray>host not configured — run larakube webmail:init {$env}</>",
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
