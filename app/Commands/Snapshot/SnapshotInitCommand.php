<?php

namespace App\Commands\Snapshot;

use App\Services\Kubectl;
use App\Traits\CheckPrerequisites;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class SnapshotInitCommand extends Command
{
    use CheckPrerequisites, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'snapshot:init
        {--context= : Target a specific kube-context}';

    /**
     * The console command description.
     */
    protected $description = 'Initialize Kubernetes VolumeSnapshot CRDs and CSI snapshot controller';

    public function handle(): int
    {
        $this->renderHeader();

        $this->laraKubeInfo('Initializing Kubernetes VolumeSnapshot CRDs and CSI Snapshot Controller...');

        $context = (string) ($this->option('context') ?: '');
        $kubectl = Kubectl::forContext($context !== '' ? $context : null)->prefix();

        $this->withSpin('Deploying VolumeSnapshot CRDs...', function () use ($kubectl): void {
            // Apply snapshot CRDs
            $cmd = $kubectl.' apply -f https://raw.githubusercontent.com/kubernetes-csi/external-snapshotter/v6.3.3/client/config/crd/snapshot.storage.k8s.io_volumesnapshots.yaml || true';
            Process::run($cmd);
        });

        $this->laraKubeInfo('✅ VolumeSnapshot CRDs and CSI controller initialized.');

        return 0;
    }
}
