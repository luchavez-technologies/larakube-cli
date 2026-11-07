<?php

namespace App\Commands\N8n;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use App\Traits\ManagesToolCustomEnv;

class N8nRemoveCommand extends AbstractToolRemoveCommand
{
    use ManagesToolCustomEnv;

    protected function tool(): ClusterTool
    {
        return ClusterTool::N8N;
    }

    protected function instanceEngine(string $kubectl, ?string $instance): ?string
    {
        return 'n8n';
    }

    /** `tool:init --tool=n8n --no-plex` labels its Deployment; that install leased no Commons tenant. */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $names = $this->names($kubectl);
        if ($names === null) {
            return false;
        }

        $deployment = $this->cluster()->get(new ResourceRef('Deployment', $names->deployment(), $names->namespace()));

        return ($deployment['metadata']['labels']['larakube-storage'] ?? null) === 'bundled';
    }

    /**
     * The Secret holding the encryption key and the data volume stay unless
     * --purge: without that key, every credential saved in n8n is unreadable,
     * even with its database intact.
     */
    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = $this->names($kubectl);
        if ($names === null) {
            return true;
        }

        $ns = $names->namespace();
        $deployment = $names->deployment();

        $workloads = [
            new ResourceRef('Deployment', $deployment, $ns),
            new ResourceRef('Service', $deployment, $ns),
            new ResourceRef('Ingress', $deployment, $ns),
            new ResourceRef('Secret', $names->secret(SecretKind::SMTP), $ns),
            new ResourceRef('ConfigMap', $this->customEnvConfigMapName($names), $ns),
            new ResourceRef('Secret', $this->customEnvSecretName($names), $ns),
        ];

        $middleware = $names->vpnMiddleware();
        if ($middleware !== null) {
            $workloads[] = $middleware;
        }

        $data = [
            new ResourceRef('PersistentVolumeClaim', $names->volume(), $ns),
            new ResourceRef('Secret', $names->secret(), $ns),
        ];

        $ok = $this->deleteResources('Removing n8n resources...', $workloads);

        // A volume can only go once its pod has: the Deployment is deleted first.
        if ($this->option('purge')) {
            $ok = $this->deleteResources('Removing n8n data volume and keys...', $data) && $ok;
        }

        return $ok;
    }

    protected function teardownWarning(string $env): array
    {
        $lines = parent::teardownWarning($env);

        $lines[] = $this->option('purge')
            ? 'The data volume and the n8n encryption key WILL BE DESTROYED.'
            : 'The data volume and the n8n encryption key WILL BE PRESERVED.';

        return $lines;
    }

    private function names(string $kubectl): ?ToolInstance
    {
        $instance = $this->resolveInstance($kubectl);

        return ($instance === null || $instance === '')
            ? null
            : ToolInstance::forInstance(ClusterTool::N8N, $instance, 'n8n');
    }
}
