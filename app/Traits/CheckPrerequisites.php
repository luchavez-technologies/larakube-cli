<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

trait CheckPrerequisites
{
    /**
     * Check if the necessary tools are installed.
     */
    protected function checkPrerequisites(bool $requireK9s = false): bool
    {
        $missing = [];

        // 1. Check Docker Installation
        if (! Process::run('which docker')->successful()) {
            $missing[] = 'Docker (https://docs.docker.com/get-docker/)';
        }

        // 2. Check Kubectl Installation
        if (! Process::run('which kubectl')->successful()) {
            $missing[] = 'kubectl (https://kubernetes.io/docs/tasks/tools/)';
        }

        // 3. Check K9s (optional but recommended)
        if ($requireK9s && ! Process::run('which k9s')->successful()) {
            warning('k9s is not installed. While not required for deployment, it is highly recommended for visualization.');
            info('Install it at: https://k9scli.io/topics/install/');
        }

        if (! empty($missing)) {
            error('The following prerequisites are missing from your system:');
            foreach ($missing as $item) {
                error("- {$item}");
            }

            return false;
        }

        // 4. Live Engine Check: Verify if Docker is actually RUNNING
        if (! Process::run('docker info')->successful()) {
            $this->laraKubeError('Docker engine is not running!');
            info('Please start OrbStack, Docker Desktop, or your local Docker daemon and try again.');

            return false;
        }

        return true;
    }
}
