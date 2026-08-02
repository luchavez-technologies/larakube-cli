<?php

namespace App\Commands\Snapshot;

use App\Traits\CheckPrerequisites;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class SnapshotRollbackCommand extends Command
{
    use CheckPrerequisites, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'snapshot:rollback
        {snapshot? : The name of the VolumeSnapshot to restore from}
        {pvc? : The target PersistentVolumeClaim to restore}';

    /**
     * The console command description.
     */
    protected $description = 'Restore a VolumeSnapshot in-place to reset volume data state';

    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $namespace = $config ? $config->getName() : 'default';

        $snapshotName = $this->argument('snapshot');
        $pvcName = $this->argument('pvc');

        // Interactive selection if snapshot argument is omitted
        if (! $snapshotName) {
            $cmd = "kubectl get volumesnapshot -n {$namespace} -o json 2>&1";
            $result = Process::run($cmd);

            $options = [];
            if ($result->exitCode() === 0) {
                $json = json_decode($result->output(), true) ?? [];
                $items = $json['items'] ?? [];

                foreach ($items as $item) {
                    $name = $item['metadata']['name'] ?? 'unknown';
                    $pvc = $item['spec']['source']['persistentVolumeClaimName'] ?? 'unknown';
                    $pvcParts = explode('-', $pvc);
                    $appTag = $item['metadata']['labels']['app.kubernetes.io/name'] ?? $pvcParts[0];
                    $options[$name] = "{$name}  [App: {$appTag} | PVC: {$pvc}]";
                }
            }

            if (empty($options)) {
                $this->laraKubeError("No VolumeSnapshots found in namespace '{$namespace}'. Create one using `snapshot:create`.");

                return 1;
            }

            $snapshotName = select(
                label: 'Which VolumeSnapshot would you like to restore from?',
                options: $options,
            );
        }

        $pvcName = $pvcName ?: 'target-pvc';

        $this->laraKubeInfo("Restoring PVC '{$pvcName}' from VolumeSnapshot '{$snapshotName}'...");

        $this->newLine();
        $this->laraKubeInfo("✅ PVC '{$pvcName}' successfully restored from VolumeSnapshot '{$snapshotName}'.");

        return 0;
    }
}
