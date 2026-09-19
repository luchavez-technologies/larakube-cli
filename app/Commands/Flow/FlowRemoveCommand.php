<?php

namespace App\Commands\Flow;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\FlowTool;
use App\Enums\SecretKind;

class FlowRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::FLOW;
    }

    /** The engine whose Deployment serves this instance. */
    protected function instanceEngine(string $kubectl, ?string $instance): ?string
    {
        foreach ($this->installedNames($instance) as $names) {
            if ($this->cluster()->exists(new ResourceRef('Deployment', $names->deployment(), $names->namespace()))) {
                return $names->engine;
            }
        }

        return null;
    }

    /** `flow:init --no-plex` labels its Deployment; that install leased no Commons tenant. */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        foreach ($this->installedNames($this->resolveInstance($kubectl)) as $names) {
            $deployment = $this->cluster()->get(new ResourceRef('Deployment', $names->deployment(), $names->namespace()));
            if ($deployment !== null) {
                return ($deployment['metadata']['labels']['larakube-storage'] ?? null) === 'bundled';
            }
        }

        return false;
    }

    /**
     * Only this instance's resources, for both engines (a host runs one, and
     * the rest are absent). The Secret holding the encryption key and the
     * data volumes stay unless --purge: without that key, every credential
     * saved in n8n is unreadable, even with its database intact.
     */
    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl);
        $workloads = [];
        $data = [];

        foreach ($this->installedNames($instance) as $names) {
            $deployment = $names->deployment();
            $ns = $names->namespace();

            array_push(
                $workloads,
                new ResourceRef('Deployment', $deployment, $ns),
                new ResourceRef('Service', $deployment, $ns),
                new ResourceRef('Ingress', $deployment, $ns),
                new ResourceRef('Secret', $names->secret(SecretKind::SMTP), $ns),
            );

            if ($names->engine === FlowTool::WINDMILL->value) {
                array_push(
                    $workloads,
                    new ResourceRef('Deployment', $names->name('db'), $ns),
                    new ResourceRef('Service', $names->name('db'), $ns),
                );
                $data[] = new ResourceRef('PersistentVolumeClaim', $names->volume('db-storage'), $ns);
            } else {
                $data[] = new ResourceRef('PersistentVolumeClaim', $names->volume(), $ns);
            }

            $data[] = new ResourceRef('Secret', $names->secret(), $ns);
        }

        if ($instance !== null && $instance !== '') {
            $middleware = ToolInstance::forInstance(ClusterTool::FLOW, $instance)->vpnMiddleware();
            if ($middleware !== null) {
                $workloads[] = $middleware;
            }
        }

        $ok = $this->deleteResources('Removing Flow resources...', $workloads);

        // A volume can only go once its pod has: the Deployments are deleted first.
        if ($this->option('purge')) {
            $ok = $this->deleteResources('Removing Flow data volumes and keys...', $data) && $ok;
        }

        return $ok;
    }

    protected function teardownWarning(string $env): array
    {
        $lines = parent::teardownWarning($env);

        $lines[] = $this->option('purge')
            ? 'Flow data volumes and the n8n encryption key WILL BE DESTROYED.'
            : 'Flow data volumes and the n8n encryption key WILL BE PRESERVED.';

        return $lines;
    }

    /** @return list<ToolInstance> one per engine; none without an instance */
    private function installedNames(?string $instance): array
    {
        if ($instance === null || $instance === '') {
            return [];
        }

        return array_map(
            fn (FlowTool $engine) => ToolInstance::forInstance(ClusterTool::FLOW, $instance, $engine->value),
            FlowTool::cases(),
        );
    }
}
