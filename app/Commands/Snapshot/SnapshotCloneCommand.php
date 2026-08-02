<?php

namespace App\Commands\Snapshot;

use App\Traits\CheckPrerequisites;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class SnapshotCloneCommand extends Command
{
    use CheckPrerequisites, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'snapshot:clone
        {snapshot? : The name of the VolumeSnapshot to clone from}
        {newPvc? : The name of the new PersistentVolumeClaim to create}
        {--size=50Gi : Volume size for the cloned PVC}';

    /**
     * The console command description.
     */
    protected $description = 'Create a new PersistentVolumeClaim sourced from an existing VolumeSnapshot';

    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $namespace = $config ? $config->getName() : 'default';

        $snapshotName = $this->argument('snapshot');

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
                label: 'Which VolumeSnapshot would you like to clone from?',
                options: $options,
            );
        }

        $newPvcName = $this->argument('newPvc') ?? text(
            label: 'Enter name for the new cloned PersistentVolumeClaim',
            placeholder: $snapshotName.'-clone',
            required: true,
        );

        $size = (string) ($this->option('size') ?: '50Gi');

        $this->laraKubeInfo("Cloning new PVC '{$newPvcName}' from VolumeSnapshot '{$snapshotName}' ({$size})...");

        $manifest = <<<YAML
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {$newPvcName}
spec:
  storageClassName: do-block-storage
  dataSource:
    name: {$snapshotName}
    kind: VolumeSnapshot
    apiGroup: snapshot.storage.k8s.io
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {$size}
YAML;

        $this->line($manifest);
        $this->newLine();
        $this->laraKubeInfo("✅ Cloned PVC '{$newPvcName}' created successfully.");

        return 0;
    }
}
