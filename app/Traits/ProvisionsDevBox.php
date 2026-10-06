<?php

namespace App\Traits;

use App\Services\Devbox\DevBoxMarker;

/**
 * Turns a fresh Ubuntu server into a development machine: hardened, with a normal login, the
 * LaraKube CLI, and the same local stack `larakube setup` builds on a developer's own computer
 * (rootless Podman, a local k3s, Traefik). It shares the hardening and user steps with
 * ProvisionsK3sNode but never installs a deployment k3s: a host has one k3s, and the two kinds
 * configure it differently.
 *
 * The using class needs InteractsWithRemoteSsh, InteractsWithServerHardening and
 * ProvisionsK3sNode (for hardenServer, createLaraKubeUser and lockDownRootLogin).
 */
trait ProvisionsDevBox
{
    /** Where the installer puts the CLI, which a non-login shell may not have on its PATH. */
    private const DEV_BOX_PATH = 'export PATH="$PATH:/usr/local/bin"';

    /**
     * The whole pipeline, as root on a reachable host. Every step is safe to run again.
     * Returns the login the box ends up with ("larakube" once root login is closed).
     */
    protected function provisionDevBox(string $name, string $ip, string $keyPath, string $channel = 'canary', ?string $adminCidr = null): ?string
    {
        $user = 'root';
        $port = '22';

        // Only SSH is open: the app is reached through a tunnel, not a public port.
        if (! $this->hardenServer($user, $ip, (int) $port, $keyPath, $adminCidr, allowPorts: [])) {
            return null;
        }

        if (! $this->createLaraKubeUser($user, $ip, $port, $keyPath)) {
            return null;
        }

        if (! $this->ensureGitInstalledOnBox($ip, $port, $keyPath)) {
            return null;
        }

        if (! $this->installCliOnBox($ip, $port, $keyPath, $channel)) {
            return null;
        }

        if (! $this->setUpLocalStackOnBox($ip, $port, $keyPath)) {
            return null;
        }

        // Lets the CLI on the box know where it is. Not worth failing the box over if it cannot be written.
        $this->runRemoteUserCommand('larakube', $ip, $port, $keyPath, DevBoxMarker::writeScript($name));

        if ($this->lockDownRootLogin($user, $ip, (int) $port, $keyPath)) {
            $user = 'larakube';
        }

        return $user;
    }

    protected function ensureGitInstalledOnBox(string $ip, string|int $port, string $keyPath): bool
    {
        $this->laraKubeInfo('Ensuring Git is installed on the box...');

        if (! $this->runRemoteUserCommand('larakube', $ip, $port, $keyPath, 'command -v git >/dev/null 2>&1 || (sudo apt-get update -y && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y git)')) {
            $this->laraKubeError('Installing Git on the box failed. See the output above; re-run to retry.');

            return false;
        }

        return true;
    }

    protected function installCliOnBox(string $ip, string|int $port, string $keyPath, string $channel): bool
    {
        $this->laraKubeInfo('Installing the LaraKube CLI on the box...');

        $flag = $channel === 'stable' ? '' : ' -s -- --canary';

        if (! $this->runRemoteUserCommand('larakube', $ip, $port, $keyPath, 'curl -fsSL https://cli.larakube.app/install.sh | bash'.$flag)) {
            $this->laraKubeError('Installing the LaraKube CLI on the box failed. See the output above; re-run to retry.');

            return false;
        }

        return true;
    }

    /** `larakube setup` on the box itself: it installs rootless Podman, the developer tools, a local k3s and Traefik. */
    protected function setUpLocalStackOnBox(string $ip, string|int $port, string $keyPath): bool
    {
        $this->laraKubeInfo('Setting up Podman and a local cluster on the box (a few minutes)...');

        if (! $this->runRemoteUserCommand('larakube', $ip, $port, $keyPath, self::DEV_BOX_PATH.'; larakube setup --profile=local --runtime=podman --no-interaction')) {
            $this->laraKubeError('`larakube setup` failed on the box. See the output above; re-run to retry.');

            return false;
        }

        return true;
    }
}
