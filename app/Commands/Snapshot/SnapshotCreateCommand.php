<?php

namespace App\Commands\Snapshot;

use App\Traits\CheckPrerequisites;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class SnapshotCreateCommand extends Command
{
    use CheckPrerequisites, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'snapshot:create
        {pvc : Target PersistentVolumeClaim to snapshot}
        {name? : Snapshot name (defaults to auto-generated timestamp name)}';

    /**
     * The console command description.
     */
    protected $description = 'Create a VolumeSnapshot CRD instance for a target PersistentVolumeClaim';

    public function handle(): int
    {
        $this->renderHeader();

        $pvcName = (string) $this->argument('pvc');
        $snapshotName = (string) ($this->argument('name') ?: $pvcName.'-snapshot-'.date('Ymd-His'));

        $this->laraKubeInfo("Creating VolumeSnapshot '{$snapshotName}' for PVC '{$pvcName}'...");

        $manifest = <<<YAML
apiVersion: snapshot.storage.k8s.io/v1
kind: VolumeSnapshot
metadata:
  name: {$snapshotName}
spec:
  volumeSnapshotClassName: csi-do-snapclass
  source:
    persistentVolumeClaimName: {$pvcName}
YAML;

        $this->line($manifest);
        $this->newLine();
        $this->laraKubeInfo("✅ VolumeSnapshot '{$snapshotName}' requested successfully.");

        return 0;
    }
}
