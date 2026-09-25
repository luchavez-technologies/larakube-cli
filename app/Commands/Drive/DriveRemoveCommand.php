<?php

namespace App\Commands\Drive;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class DriveRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DRIVE;
    }

    protected function teardownWarning(string $env): array
    {
        return [
            "Drive (oCIS) will be REMOVED from '{$env}':",
            'Deployments, Services, Ingresses and access middleware in larakube-shared',
            'Drive file data (S3 bucket / PVC) and the encryption keys are PRESERVED — even with --purge',
        ];
    }

    /**
     * oCIS wraps each file's encryption key with the rekey key in this
     * instance's credentials Secret — dropping the Commons bucket without also
     * handling per-file re-encryption would orphan data no re-init could
     * recover. See teardown()'s docblock.
     */
    protected function preservesBucketsOnPurge(): bool
    {
        return true;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $ok = $this->removeResources(
            'Removing Drive resources...',
            $this->teardownComponentsCommand($kubectl, $namespace, $this->resolveInstance($kubectl)),
        );

        $middleware = $this->tool()->vpnMiddlewareTarget($this->resolveInstance($kubectl));
        if ($middleware !== null) {
            Process::run("{$kubectl} delete middleware/{$middleware['name']} -n {$middleware['namespace']} --ignore-not-found 2>/dev/null");
        }

        // Deliberately NOT deleted: the S3 bucket contents, the metadata PVC,
        // AND the credentials Secret. oCIS wraps each file's encryption key
        // with the rekey key, so deleting the Secret while keeping the data
        // would orphan every uploaded file (undecryptable once a re-init
        // regenerates new keys). A mistyped `drive:remove` must not be able to
        // destroy files — only workloads and access middleware go; data and
        // keys go by hand. Both are left out of the vendor's component
        // resource list for the same reason.
        $this->laraKubeInfo('Drive file data (S3 bucket / PVC) and the encryption keys were left in place. Delete them manually if you meant to.');

        return $ok;
    }
}
