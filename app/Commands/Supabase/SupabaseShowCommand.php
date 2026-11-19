<?php

namespace App\Commands\Supabase;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;

class SupabaseShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SUPABASE;
    }

    protected function afterTable(?string $host, string $env, string $instance = ''): void
    {
        $creds = $this->credentials($host, $env, $instance);

        if ($creds !== null) {
            $this->newLine();
            $this->line('  <fg=gray>Studio login (basic auth):</>');
            $this->line("  <fg=gray>Username:</>  <fg=blue>{$creds['admin_username']}</>");
            $this->line("  <fg=gray>Password:</>  <fg=yellow>{$creds['admin_password']}</>");
            $this->newLine();
        }
    }

    /**
     * Studio sits behind the gateway's basic auth; its login lives in the instance's Secret
     * (also in OpenBao as SUPABASE_DASHBOARD_PASSWORD).
     *
     * @return array{admin_username: string, admin_password: string}|null
     */
    protected function credentials(?string $host, string $env, string $instance = ''): ?array
    {
        if ($instance === '') {
            return null;
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $names = ToolInstance::forInstance(ClusterTool::SUPABASE, $instance);
        $data = Kubectl::forContext($context)->raw(['get', 'secret', $names->secret(), '-n', $names->namespace(), '-o', 'json', '--ignore-not-found'])->json()['data'] ?? [];

        $username = base64_decode((string) ($data['dashboard-username'] ?? ''));
        $password = base64_decode((string) ($data['dashboard-password'] ?? ''));

        return $username !== '' && $password !== ''
            ? ['admin_username' => $username, 'admin_password' => $password]
            : null;
    }
}
