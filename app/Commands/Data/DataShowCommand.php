<?php

namespace App\Commands\Data;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithData;

class DataShowCommand extends AbstractToolShowCommand
{
    use InteractsWithData;

    protected function tool(): ClusterTool
    {
        return ClusterTool::DATA;
    }

    /**
     * A Data instance can run either engine, and nothing about the host or
     * URL reveals which — the registry's `engine` field (recorded by
     * data:init) is the only place this is answered without a live
     * `kubectl get deployment` probe.
     */
    protected function rows(?string $host, string $env, string $kubectl, string $instance = 'main'): array
    {
        $rows = parent::rows($host, $env, $kubectl, $instance);

        $engine = $this->findToolInstanceEntry($kubectl, ClusterTool::DATA, $instance)['engine'] ?? null;
        if ($engine !== null) {
            $rows[] = ['Engine', ucfirst($engine)];
        }

        return $rows;
    }

    protected function afterTable(?string $host, string $env, string $instance = 'main'): void
    {
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->dataKubectl($context);
        $ns = $this->dataNamespace();

        $adminEmail = $this->readDataSecret($kubectl, $ns, 'admin-email', $instance);
        $adminPassword = $this->readDataSecret($kubectl, $ns, 'admin-password', $instance);

        if ($adminEmail || $adminPassword) {
            $this->newLine();
            $this->line('  <fg=gray>Bootstrap Admin Credentials:</>');
            if ($adminEmail) {
                $this->line("  <fg=gray>Admin Email:</>     <fg=blue>{$adminEmail}</>");
            }
            if ($adminPassword) {
                $this->line("  <fg=gray>Admin Password:</>  <fg=yellow>{$adminPassword}</>");
            }
            $this->newLine();
        }
    }
}
