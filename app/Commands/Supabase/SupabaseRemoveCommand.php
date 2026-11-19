<?php

namespace App\Commands\Supabase;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;

class SupabaseRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SUPABASE;
    }

    /** A `--no-plex` install keeps files on its own volume and never leased a Commons bucket to drop. */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $names = $this->names($kubectl);

        return $names !== null && $this->cluster()->exists(new ResourceRef('PersistentVolumeClaim', $names->volume('storage'), $namespace));
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = $this->names($kubectl);
        if ($names === null) {
            return true;
        }

        $ns = $names->namespace();

        $refs = [
            new ResourceRef('Deployment', $names->deployment(), $ns),
            new ResourceRef('Service', $names->deployment(), $ns),
            new ResourceRef('ConfigMap', $names->name('envoy-config'), $ns),
            new ResourceRef('Ingress', $names->deployment(), $ns),
            new ResourceRef('Deployment', $names->name('studio'), $ns),
            new ResourceRef('Service', $names->name('studio'), $ns),
            new ResourceRef('Deployment', $names->name('auth'), $ns),
            new ResourceRef('Service', $names->name('auth'), $ns),
            new ResourceRef('Deployment', $names->name('rest'), $ns),
            new ResourceRef('Service', $names->name('rest'), $ns),
            new ResourceRef('Deployment', $names->name('meta'), $ns),
            new ResourceRef('Service', $names->name('meta'), $ns),
            new ResourceRef('Deployment', $names->name('storage'), $ns),
            new ResourceRef('Service', $names->name('storage'), $ns),
            new ResourceRef('Deployment', $names->name('realtime'), $ns),
            new ResourceRef('Service', $names->name('realtime'), $ns),
            new ResourceRef('Deployment', $names->name('functions'), $ns),
            new ResourceRef('Service', $names->name('functions'), $ns),
            new ResourceRef('Deployment', $names->name('imgproxy'), $ns),
            new ResourceRef('Service', $names->name('imgproxy'), $ns),
            new ResourceRef('Deployment', $names->name('supavisor'), $ns),
            new ResourceRef('Service', $names->name('supavisor'), $ns),
            new ResourceRef('StatefulSet', $names->name('db'), $ns),
            new ResourceRef('Service', $names->name('db'), $ns),
            new ResourceRef('ConfigMap', $names->name('db-init'), $ns),
            new ResourceRef('Secret', $names->secret(SecretKind::SMTP), $ns),
        ];

        if ((bool) $this->option('purge')) {
            $refs[] = new ResourceRef('Secret', $names->secret(), $ns);
            $refs[] = new ResourceRef('PersistentVolumeClaim', $names->volume('db'), $ns);
            $refs[] = new ResourceRef('PersistentVolumeClaim', $names->volume('pgsodium'), $ns);
            $refs[] = new ResourceRef('PersistentVolumeClaim', $names->volume('storage'), $ns);
        }

        return $this->cluster()->delete(...$refs)->ok;
    }

    private function names(string $kubectl): ?ToolInstance
    {
        $instance = $this->resolveInstance($kubectl);

        return ($instance === null || $instance === '')
            ? null
            : ToolInstance::forInstance(ClusterTool::SUPABASE, $instance);
    }
}
