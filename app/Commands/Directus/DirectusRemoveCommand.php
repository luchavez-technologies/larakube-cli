<?php

namespace App\Commands\Directus;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use App\Traits\InteractsWithData;
use LogicException;

class DirectusRemoveCommand extends AbstractToolRemoveCommand
{
    use InteractsWithData;

    protected $signature = 'directus:remove
        {environment=local : Environment to remove Directus from}
        {--context=  : Target a specific kube-context}
        {--domain=   : The instance\'s domain/host — omit for the default instance}
        {--all       : Remove all registered instances of Directus}
        {--purge     : Also destroy persistent data — drop the Plex Commons database and release the Redis index. Irreversible.}
        {--force     : Skip the confirmation prompt}';

    protected function tool(): ClusterTool
    {
        return ClusterTool::DIRECTUS;
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

        $instance ??= throw new LogicException('directus: an instance is always a host-derived slug, never empty.');

        $names = ToolInstance::forInstance(ClusterTool::DIRECTUS, $instance, 'directus');
        $deploy = $names->deployment();

        $this->laraKubeInfo("Removing Directus for instance '{$instance}'...");

        return $this->deleteResources('Removing Directus resources...', [
            new ResourceRef('Deployment', $deploy, $namespace),
            new ResourceRef('Service', $deploy, $namespace),
            new ResourceRef('Ingress', $deploy, $namespace),
            new ResourceRef('Secret', $names->secret(), $namespace),
            new ResourceRef('Secret', $names->secret(SecretKind::SMTP), $namespace),
            new ResourceRef('Secret', $names->secret(SecretKind::OIDC), $namespace),
        ]);
    }
}
