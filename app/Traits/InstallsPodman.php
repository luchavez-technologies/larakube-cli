<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * Install rootless Podman on a Debian/Ubuntu (apt) host — shared by `setup` and
 * the `up` WSL runtime menu so the install lives in exactly one place.
 *
 * Requires the composing command to also use CollectsReminders (`$reminders`),
 * ResolvesContainerRuntime (`podmanIsFunctional()`), StreamsProcessOutput
 * (`runStreaming()`) and LaraKubeOutput.
 */
trait InstallsPodman
{
    /**
     * The three companions are what make it rootless: user-mode networking
     * (slirp4netns), an unprivileged overlay driver (fuse-overlayfs), and the
     * subuid/subgid tooling (uidmap). No daemon, no `systemctl`, no docker group
     * — so there's no group-membership trap to warn about afterward.
     */
    protected function installRootlessPodman(): bool
    {
        if (! $this->isDebianLike()) {
            $this->laraKubeWarn('Automatic Podman install currently supports Debian/Ubuntu (apt) hosts only.');
            $this->line('  Install rootless Podman for your distro, then re-run: <fg=cyan>https://podman.io/docs/installation</>');

            return false;
        }

        $this->beforePodmanInstall();

        $this->laraKubeInfo('Installing rootless Podman...');
        $code = $this->runStreaming('sudo apt-get install -y podman slirp4netns fuse-overlayfs uidmap');

        if ($code !== 0) {
            $this->laraKubeError('Podman installation failed. See output above.');

            return false;
        }

        $this->configurePodmanShortNames();

        // `podman save | k3s ctr images import -` is how larakube sideloads a
        // built image into the local cluster, so confirm the engine actually
        // answers rootless before calling it done.
        if ($this->podmanIsFunctional()) {
            $this->laraKubeInfo('✅ Rootless Podman installed.');

            return true;
        }

        // Installed but not yet answering — almost always the subuid/subgid
        // range needing a fresh login to take effect.
        $this->laraKubeWarn('Podman installed, but `podman info` did not succeed yet.');
        $this->line('  Rootless Podman needs your subuid/subgid range active — open a new terminal (or run <fg=cyan>podman system migrate</>) and re-run <fg=cyan>larakube setup</>.');
        $this->reminders[] = 'Open a new terminal and re-run <fg=cyan>larakube setup</> — rootless Podman needs a fresh login to pick up its subuid/subgid range.';

        return false;
    }

    /**
     * Let Podman resolve Docker Hub short names. Unlike Docker, Podman has no
     * built-in default registry, so `podman pull serversideup/php:8.5-cli-alpine`
     * fails with "short-name ... did not resolve to an alias and no
     * unqualified-search registries are defined" until docker.io is registered.
     * Every builder image the CLI pulls (serversideup/php, node, python, …) is a
     * Docker Hub short name, so this is required for `larakube new`/scaffolders.
     */
    protected function configurePodmanShortNames(): void
    {
        $this->runStreaming(
            'echo '.escapeshellarg('unqualified-search-registries = ["docker.io"]')
            .' | sudo tee /etc/containers/registries.conf.d/larakube.conf >/dev/null',
        );
    }

    /** Whether apt-get is available (Debian/Ubuntu, incl. the default WSL distros). */
    protected function isDebianLike(): bool
    {
        return trim(Process::run('command -v apt-get')->output()) !== '';
    }

    /**
     * Hook for an optional pre-install step (e.g. `apt-get upgrade` on a fresh
     * box). No-op by default; `setup` overrides it.
     */
    protected function beforePodmanInstall(): void {}
}
