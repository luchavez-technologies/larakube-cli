<?php

namespace App\Commands\Runtime;

use App\Traits\CollectsReminders;
use App\Traits\InstallsPodman;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesContainerRuntime;
use App\Traits\StreamsProcessOutput;
use LaravelZero\Framework\Commands\Command;

class RuntimeInstallCommand extends Command
{
    use CollectsReminders, InstallsPodman, LaraKubeOutput, ResolvesContainerRuntime, StreamsProcessOutput;

    protected $signature = 'runtime:install
        {--runtime=podman : Container runtime to install (only podman today)}
        {--update : Update an already-installed runtime instead of leaving it alone}';

    protected $description = 'Install the container runtime that builds your apps (rootless Podman) without a full local setup';

    public function handle(): int
    {
        $this->renderHeader();

        if (strtolower(trim((string) $this->option('runtime'))) !== 'podman') {
            $this->laraKubeError('Only --runtime=podman is supported. Install Docker from https://docs.docker.com/get-docker/.');

            return 1;
        }

        if ($this->option('update')) {
            if (! $this->podmanIsFunctional()) {
                $this->laraKubeError('Rootless Podman is not installed — nothing to update. Run without --update first.');

                return 1;
            }

            return $this->updateRootlessPodman() ? 0 : 1;
        }

        if ($this->podmanIsFunctional()) {
            $this->laraKubeInfo('Rootless Podman is already installed and working.');

            return 0;
        }

        $installed = $this->installRootlessPodman();
        $this->renderReminders();

        return $installed ? 0 : 1;
    }
}
