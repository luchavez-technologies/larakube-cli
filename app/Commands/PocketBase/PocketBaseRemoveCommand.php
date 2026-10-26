<?php

namespace App\Commands\PocketBase;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use App\Traits\InteractsWithData;
use Illuminate\Support\Facades\Process;
use LogicException;

class PocketBaseRemoveCommand extends AbstractToolRemoveCommand
{
    use InteractsWithData;

    protected $signature = 'pocketbase:remove
        {environment=local : Environment to remove PocketBase from}
        {--context=  : Target a specific kube-context}
        {--domain=   : The instance\'s domain/host — omit for the default instance}
        {--all       : Remove all registered instances of PocketBase}
        {--purge     : Also destroy persistent data. Irreversible.}
        {--force     : Skip the confirmation prompt}';

    protected function tool(): ClusterTool
    {
        return ClusterTool::POCKETBASE;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl);
        if ($instance === null || $instance === '') {
            $domain = (string) ($this->option('domain') ?? '');
            if ($domain !== '') {
                $instance = $this->tool()->instanceSlugFromHost($domain);
            } else {
                $host = $this->singleRecordedToolHost($kubectl, $this->tool())
                    ?? $this->tool()->service()?->hostFor(\App\Data\GlobalConfigData::load()->getLocalTld());
                $instance = $host !== null ? $this->tool()->instanceSlugFromHost($host) : null;
            }
        }

        $instance ??= throw new LogicException('pocketbase: an instance is always a host-derived slug, never empty.');

        $names = ToolInstance::forInstance(ClusterTool::POCKETBASE, $instance, 'pocketbase');
        $deploy = $names->deployment();

        $this->laraKubeInfo("Removing PocketBase for instance '{$instance}'...");

        $ok = $this->deleteResources('Removing PocketBase resources...', [
            new ResourceRef('Deployment', $deploy, $namespace),
            new ResourceRef('Service', $deploy, $namespace),
            new ResourceRef('Ingress', "{$deploy}-ingress", $namespace),
            new ResourceRef('ConfigMap', $names->configMap('hooks'), $namespace),
            new ResourceRef('Secret', $names->secret(), $namespace),
            new ResourceRef('Secret', $names->secret(SecretKind::SMTP), $namespace),
            new ResourceRef('Secret', $names->secret(SecretKind::OIDC), $namespace),
        ]);

        if ($this->option('purge')) {
            // Result is never read — `2>/dev/null || true` was only ever
            // there to keep the shell happy about a nonzero exit nothing checks.
            Process::run("{$kubectl} wait --for=delete pod -l app.kubernetes.io/name={$deploy} -n {$namespace} --timeout=60s");

            $ok = $this->deleteResources('Removing PocketBase storage...', [
                new ResourceRef('PersistentVolumeClaim', $names->volume(), $namespace),
            ]) && $ok;
        }

        return $ok;
    }

    protected function teardownWarning(string $env): array
    {
        $lines = parent::teardownWarning($env);

        if ($this->option('purge')) {
            $lines[] = 'PocketBase data volume WILL BE DESTROYED: its database, users and uploaded files.';
        }

        return $lines;
    }
}
