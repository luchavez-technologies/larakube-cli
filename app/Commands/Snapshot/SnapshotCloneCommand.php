<?php

namespace App\Commands\Snapshot;

use App\Traits\CheckPrerequisites;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class SnapshotCloneCommand extends Command
{
    use CheckPrerequisites, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'snapshot:clone
        {snapshot? : The name of the VolumeSnapshot to clone from}
        {--pvc= : Name for the NEW PersistentVolumeClaim to create. Must not already exist.}
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

        $newPvcName = (string) ($this->option('pvc') ?: '') ?: text(
            label: 'Enter name for the new cloned PersistentVolumeClaim',
            placeholder: $snapshotName.'-clone',
            required: true,
        );

        $size = (string) ($this->option('size') ?: '50Gi');

        // --pvc names a volume being CREATED. Refusing an existing name is what
        // makes that true rather than merely documented: kubectl apply onto a
        // live PVC would be a no-op that reported success, leaving the operator
        // believing a restore had happened to a volume still holding its old
        // data. It is also why this and rollback can share one flag name.
        if ($this->pvcExists($namespace, $newPvcName)) {
            $this->laraKubeError("A PersistentVolumeClaim named '{$newPvcName}' already exists in '{$namespace}'.");
            $this->newLine();
            $this->line('  <fg=gray>Clone creates a new volume. To restore onto an existing one, use</>');
            $this->line('  <fg=blue>  larakube snapshot:rollback</><fg=gray> instead.</>');
            $this->newLine();

            return 1;
        }

        $this->laraKubeInfo("Cloning new PVC '{$newPvcName}' from VolumeSnapshot '{$snapshotName}' ({$size})...");

        $manifest = <<<YAML
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {$newPvcName}
  namespace: {$namespace}
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

        if (! $this->applyManifest($manifest, "snapshot-clone-{$newPvcName}")) {
            $this->laraKubeError("Failed to create PVC '{$newPvcName}'.");

            return 1;
        }

        $this->laraKubeInfo("✅ Cloned PVC '{$newPvcName}' created in '{$namespace}'.");
        $this->newLine();
        $this->line('  <fg=gray>It stays Pending until a workload mounts it — that is normal for</>');
        $this->line('  <fg=gray>WaitForFirstConsumer storage, not a failure.</>');
        $this->newLine();

        return 0;
    }

    protected function pvcExists(string $namespace, string $pvc): bool
    {
        return trim(Process::run(
            'kubectl get pvc '.escapeshellarg($pvc).' -n '.escapeshellarg($namespace).' --no-headers --ignore-not-found',
        )->output()) !== '';
    }

    protected function applyManifest(string $yaml, string $name): bool
    {
        $directory = TemporaryDirectory::make();
        $path = $directory->path("larakube-{$name}.yaml");
        file_put_contents($path, $yaml);

        $applied = Process::run('kubectl apply -f '.escapeshellarg($path))->successful();
        $directory->delete();

        return $applied;
    }
}
