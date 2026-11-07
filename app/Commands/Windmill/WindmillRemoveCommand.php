<?php

namespace App\Commands\Windmill;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;

class WindmillRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::WINDMILL;
    }

    protected function instanceEngine(string $kubectl, ?string $instance): ?string
    {
        return 'windmill';
    }

    /** `tool:init --tool=windmill --no-plex` labels its Deployment; that install leased no Commons tenant. */
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
     * The Secret holding the encryption key and the data volumes stay unless
     * --purge: without that key, every credential saved in Windmill is
     * unreadable, even with its database intact.
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
            // Windmill's own bundled database, distinct from any Commons tenant.
            new ResourceRef('Deployment', $names->name('db'), $ns),
            new ResourceRef('Service', $names->name('db'), $ns),
        ];

        $middleware = $names->vpnMiddleware();
        if ($middleware !== null) {
            $workloads[] = $middleware;
        }

        $data = [
            new ResourceRef('PersistentVolumeClaim', $names->volume('db-storage'), $ns),
            new ResourceRef('Secret', $names->secret(), $ns),
        ];

        $ok = $this->deleteResources('Removing Windmill resources...', $workloads);

        // A volume can only go once its pod has: the Deployments are deleted first.
        if ($this->option('purge')) {
            $ok = $this->deleteResources('Removing Windmill data volumes and keys...', $data) && $ok;
        }

        return $ok;
    }

    protected function teardownWarning(string $env): array
    {
        $lines = parent::teardownWarning($env);

        $lines[] = $this->option('purge')
            ? 'Data volumes and the Windmill encryption key WILL BE DESTROYED.'
            : 'Data volumes and the Windmill encryption key WILL BE PRESERVED.';

        return $lines;
    }

    private function names(string $kubectl): ?ToolInstance
    {
        $instance = $this->resolveInstance($kubectl);

        return ($instance === null || $instance === '')
            ? null
            : ToolInstance::forInstance(ClusterTool::WINDMILL, $instance, 'windmill');
    }
}
