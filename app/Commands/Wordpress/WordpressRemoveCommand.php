<?php

namespace App\Commands\Wordpress;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use App\Traits\InteractsWithData;
use Illuminate\Support\Facades\Process;
use LogicException;

class WordpressRemoveCommand extends AbstractToolRemoveCommand
{
    use InteractsWithData;

    protected $signature = 'wordpress:remove
        {environment=local : Environment to remove WordPress from}
        {--context=  : Target a specific kube-context}
        {--domain=   : The instance\'s domain/host — omit for the default instance}
        {--all       : Remove all registered instances of WordPress}
        {--purge     : Also destroy persistent data — drop the Plex Commons database or delete PVC. Irreversible.}
        {--force     : Skip the confirmation prompt}';

    protected function tool(): ClusterTool
    {
        return ClusterTool::WORDPRESS;
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

        $instance ??= throw new LogicException('wordpress: an instance is always a host-derived slug, never empty.');

        $names = ToolInstance::forInstance(ClusterTool::WORDPRESS, $instance, 'wordpress');
        $deploy = $names->deployment();

        $this->laraKubeInfo("Removing WordPress for instance '{$instance}'...");

        $ok = $this->deleteResources('Removing WordPress resources...', [
            new ResourceRef('Deployment', $deploy, $namespace),
            new ResourceRef('Service', $deploy, $namespace),
            new ResourceRef('Ingress', $deploy, $namespace),
            new ResourceRef('Secret', $names->secret(), $namespace),
            new ResourceRef('Secret', $names->secret(SecretKind::SMTP), $namespace),
        ]);

        if ($this->option('purge')) {
            // Result is never read — `2>/dev/null || true` was only ever
            // there to keep the shell happy about a nonzero exit nothing checks.
            Process::run("{$kubectl} wait --for=delete pod -l app.kubernetes.io/name={$deploy} -n {$namespace} --timeout=60s");

            $ok = $this->deleteResources('Removing WordPress storage...', [
                new ResourceRef('PersistentVolumeClaim', $names->volume(), $namespace),
            ]) && $ok;
        }

        return $ok;
    }
}
