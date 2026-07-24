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

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run("{$kubectl} get secret drive-secrets -n {$namespace}")->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        // Both engines are deleted regardless of which is installed — the engine
        // is switchable between installs.
        $ok = $this->removeResources(
            'Removing Drive resources...',
            "{$kubectl} delete deployment/drive-ocis deployment/drive-nextcloud "
            .'service/drive-ocis service/drive-nextcloud '
            .'ingress/drive-ocis ingress/drive-nextcloud '
            ."secret/drive-secrets -n {$namespace} --ignore-not-found",
        );

        Process::run("{$kubectl} delete middleware/drive-vpn-only -n {$namespace} --ignore-not-found 2>/dev/null");

        // Deliberately NOT deleted: the S3 bucket contents and the PVCs. Drive
        // holds user-uploaded files, so a mistyped `drive:remove` must not be
        // able to destroy them — they are removed by hand or by deleting the PVC.
        $this->laraKubeInfo('Drive file data (S3 bucket / PVC) was left in place. Delete it manually if you meant to.');

        return $ok;
    }
}
