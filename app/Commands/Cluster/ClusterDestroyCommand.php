<?php

namespace App\Commands\Cluster;

use App\Traits\LaraKubeOutput;
use App\Traits\PrunesKubeContext;
use App\Traits\StreamsProcessOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class ClusterDestroyCommand extends Command
{
    use LaraKubeOutput, PrunesKubeContext, StreamsProcessOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cluster:destroy';

    /**
     * The console command description.
     */
    protected $description = 'Completely remove the local Kubernetes cluster';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local Cluster Destroyer');

        if (! confirm('Are you absolutely sure? This will delete ALL namespaces and data in the native k3s cluster.', false)) {
            $this->laraKubeInfo('Action cancelled.');

            return 0;
        }

        return $this->destroyK3s();
    }

    protected function destroyK3s(): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->laraKubeError('Native k3s is only on Linux.');

            return 1;
        }

        // Prune the kubeconfig context BEFORE uninstalling — k3s-uninstall.sh may
        // remove the k3s-provided kubectl, so do it while kubectl is still around.
        $this->pruneKubeContext(['k3s-larakube']);

        $this->withSpin('Uninstalling k3s...', function () {
            $this->runStreaming('/usr/local/bin/k3s-uninstall.sh');

            return true;
        });
        $this->laraKubeInfo('✅ k3s service uninstalled.');

        return 0;
    }
}
