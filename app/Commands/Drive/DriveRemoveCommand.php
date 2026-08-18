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
            'Drive file data (S3 bucket / PVC) and the drive-secrets encryption keys are PRESERVED — even with --purge',
        ];
    }

    /**
     * oCIS wraps each file's encryption key with drive-secrets' rekey key —
     * dropping the Commons bucket without also handling per-file
     * re-encryption would orphan data no re-init could recover. See
     * teardown()'s docblock.
     */
    protected function preservesBucketsOnPurge(): bool
    {
        return true;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        // The legacy Nextcloud engine resources are cleaned up too, so a cluster
        // that still carries a pre-removal install is fully torn down.
        $ok = $this->removeResources(
            'Removing Drive resources...',
            "{$kubectl} delete deployment/drive-ocis deployment/drive-nextcloud "
            .'service/drive-ocis service/drive-nextcloud '
            .'ingress/drive-ocis ingress/drive-nextcloud '
            ."-n {$namespace} --ignore-not-found",
        );

        Process::run("{$kubectl} delete middleware/drive-vpn-only -n {$namespace} --ignore-not-found 2>/dev/null");

        // SMTP/OIDC credential Secrets left behind by mail:wire / sso:wire are
        // orphaned once the deployment is gone — clean them up here.
        Process::run("{$kubectl} delete secret drive-ocis-smtp drive-ocis-oidc -n {$namespace} --ignore-not-found 2>/dev/null");

        // Deliberately NOT deleted: the S3 bucket contents, the PVCs, AND the
        // drive-secrets encryption keys. oCIS wraps each file's encryption key
        // with the rekey key, so deleting the secret while keeping the data
        // would orphan every uploaded file on production (undecryptable after a
        // re-init regenerates new keys). A mistyped `drive:remove` must not be
        // able to destroy files — only workloads and access middleware are
        // removed; data and keys go by hand.
        $this->laraKubeInfo('Drive file data (S3 bucket / PVC) and the drive-secrets encryption keys were left in place. Delete them manually if you meant to.');

        return $ok;
    }
}
