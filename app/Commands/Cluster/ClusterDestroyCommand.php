<?php

namespace App\Commands\Cluster;

use App\Traits\LaraKubeOutput;
use App\Traits\PrunesKubeContext;
use App\Traits\StreamsProcessOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

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

        $engine = select(
            label: 'Which cluster would you like to destroy?',
            options: [
                'k3d' => 'k3d cluster (larakube)',
                'k3s' => 'Native k3s service (Linux)',
            ],
            default: 'k3d',
        );

        if (! confirm("Are you absolutely sure? This will delete ALL namespaces and data in the '{$engine}' cluster.", false)) {
            $this->laraKubeInfo('Action cancelled.');

            return 0;
        }

        if ($engine === 'k3d') {
            return $this->destroyK3d();
        }

        return $this->destroyK3s();
    }

    protected function destroyK3d(): int
    {
        $this->withSpin('Deleting k3d cluster...', function () {
            $this->runStreaming('k3d cluster delete larakube');

            return true;
        });

        // Remove the stale kubeconfig entry so a later cluster:setup is seamless
        // (otherwise a dangling current-context breaks k9s/kubectl on WSL).
        $this->pruneKubeContext(['k3d-larakube']);

        $this->laraKubeInfo('✅ k3d cluster destroyed.');

        return 0;
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
