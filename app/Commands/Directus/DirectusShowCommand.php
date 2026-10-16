<?php

namespace App\Commands\Directus;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\InteractsWithData;

class DirectusShowCommand extends AbstractToolShowCommand
{
    use InteractsWithData;

    protected function tool(): ClusterTool
    {
        return ClusterTool::DIRECTUS;
    }

    protected function afterTable(?string $host, string $env, string $instance = ''): void
    {
        $creds = $this->credentials($host, $env, $instance);

        if ($creds !== null) {
            $this->newLine();
            $this->line('  <fg=gray>Bootstrap Admin Credentials:</>');
            if (isset($creds['admin_email'])) {
                $this->line("  <fg=gray>Admin Email:</>     <fg=blue>{$creds['admin_email']}</>");
            }
            if (isset($creds['admin_password'])) {
                $this->line("  <fg=gray>Admin Password:</>  <fg=yellow>{$creds['admin_password']}</>");
            }
            $this->newLine();
        }
    }

    protected function credentials(?string $host, string $env, string $instance = ''): ?array
    {
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->dataNamespace();

        $adminEmail = $this->readDataSecret($kubectl, $ns, 'admin-email', $instance, 'directus');
        $adminPassword = $this->readDataSecret($kubectl, $ns, 'admin-password', $instance, 'directus');

        if (! $adminEmail && ! $adminPassword) {
            return null;
        }

        return array_filter([
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
        ], fn (?string $v): bool => $v !== null);
    }
}
