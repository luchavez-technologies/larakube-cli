<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

trait CheckPrerequisites
{
    use ResolvesContainerRuntime;

    /**
     * Check that the tools larakube needs are installed and responding: a
     * container runtime — rootless Podman (the default on WSL/Linux) OR Docker,
     * either of which builds and sideloads images — plus kubectl.
     */
    protected function checkPrerequisites(bool $requireK9s = false): bool
    {
        $missing = [];

        // 1. A container runtime — Podman or Docker. (Was Docker-only, which
        //    wrongly failed on a Podman host after `larakube setup`.) `command -v`
        //    exits 0 when the binary is on PATH, non-zero otherwise.
        $hasPodman = Process::run('command -v podman')->successful();
        $hasDocker = Process::run('command -v docker')->successful();

        if (! $hasPodman && ! $hasDocker) {
            $missing[] = 'a container runtime — install rootless Podman with `larakube setup`, or Docker (https://docs.docker.com/get-docker/)';
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

        // 4. Live engine check — the runtime must actually respond, not merely be
        //    on PATH: a fresh rootless Podman awaiting a new shell, or a stopped
        //    Docker daemon, both fail here.
        if (! $this->podmanIsFunctional() && ! $this->dockerIsFunctional()) {
            $this->laraKubeError('Your container runtime is installed but not responding.');
            info('Podman: a fresh rootless install needs a new shell — open one and retry. Docker: start OrbStack, Docker Desktop, or your local daemon.');

            return false;
        }

        return true;
    }
}
