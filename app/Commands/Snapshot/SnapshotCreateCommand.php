<?php

namespace App\Commands\Snapshot;

use App\Traits\CheckPrerequisites;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class SnapshotCreateCommand extends Command
{
    use CheckPrerequisites, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The PVC is the subject -- you are snapshotting a volume -- and the
     * snapshot's own name is optional because it defaults to a timestamp.
     */
    protected $signature = 'snapshot:create
        {pvc : Target PersistentVolumeClaim to snapshot}
        {--name= : Snapshot name (defaults to an auto-generated timestamp name)}';

    protected $description = 'Create a VolumeSnapshot CRD instance for a target PersistentVolumeClaim';

    public function handle(): int
    {
        $this->renderHeader();

        $namespace = $this->getProjectConfig(getcwd())?->getName() ?: 'default';
        $pvcName = (string) $this->argument('pvc');
        $snapshotName = (string) ($this->option('name') ?: $pvcName.'-snapshot-'.date('Ymd-His'));

        // Snapshotting a PVC that does not exist yields a VolumeSnapshot stuck
        // at readyToUse=false forever, which looks like a slow snapshot rather
        // than a mistake. Fail on the name instead.
        if (! $this->pvcExists($namespace, $pvcName)) {
            $this->laraKubeError("No PersistentVolumeClaim '{$pvcName}' in namespace '{$namespace}'.");

            return 1;
        }

        $manifest = <<<YAML
apiVersion: snapshot.storage.k8s.io/v1
kind: VolumeSnapshot
metadata:
  name: {$snapshotName}
  namespace: {$namespace}
spec:
  volumeSnapshotClassName: csi-do-snapclass
  source:
    persistentVolumeClaimName: {$pvcName}
YAML;

        if (! $this->applyManifest($manifest, "snapshot-create-{$snapshotName}")) {
            $this->laraKubeError("Failed to create VolumeSnapshot '{$snapshotName}'.");

            return 1;
        }

        $this->laraKubeInfo("✅ VolumeSnapshot '{$snapshotName}' created in '{$namespace}'.");
        $this->newLine();
        // Creation is accepted immediately; the CSI driver fills readyToUse in
        // afterwards. Saying "created" without saying that invites restoring
        // from a snapshot that has not finished.
        $this->line('  <fg=gray>The volume is not captured until readyToUse is true. Check with:</>');
        $this->line("  <fg=blue>  kubectl get volumesnapshot {$snapshotName} -n {$namespace}</>");
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
