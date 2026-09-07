<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

trait DetectsWsl
{
    /**
     * Whether we're running inside WSL — where the Windows side (hosts file and
     * certificate trust store) must be targeted too, not just the Linux ones.
     *
     * Matches WSL2 (lowercase "microsoft" in /proc/version, e.g.
     * "…-microsoft-standard-WSL2") as well as WSL1 ("Microsoft"). The previous
     * case-sensitive `str_contains(..., 'Microsoft')` checks missed WSL2
     * entirely — so `larakube trust` installed the CA into the Linux store
     * instead of the Windows Root store, and the Windows browser never trusted
     * the local HTTPS cert.
     */
    protected function isWsl(): bool
    {
        if (getenv('WSL_DISTRO_NAME')) {
            return true;
        }

        return $this->wslKernelSignaturePresent();
    }

    /**
     * Whether /proc/version reports a Microsoft-patched kernel — the WSL
     * signature independent of WSL_DISTRO_NAME. Split out so tests can force
     * "not WSL" deterministically, since the suite may itself be running on a
     * real WSL2 host (where this would otherwise always be true).
     */
    protected function wslKernelSignaturePresent(): bool
    {
        return is_file('/proc/version')
            && str_contains(strtolower((string) @file_get_contents('/proc/version')), 'microsoft');
    }

    /**
     * The active WSL2 networking mode as reported by `wslinfo` — 'nat' (the
     * default) or 'mirrored'. Empty string off WSL or when wslinfo is absent
     * (older WSL builds). This is the RUNNING mode, which differs from whatever
     * ~/.wslconfig requests until the next `wsl --shutdown`.
     */
    protected function wslNetworkingMode(): string
    {
        if (! $this->isWsl()) {
            return '';
        }

        return strtolower(trim(Process::run('wslinfo --networking-mode')->output()));
    }

    /**
     * Whether WSL2 mirrored networking is ACTIVE. In mirrored mode Windows and
     * WSL share localhost, so the cluster ingress is reachable from the Windows
     * browser at a stable 127.0.0.1 — no churning node IP, so a Windows hosts
     * entry written once never goes stale.
     */
    protected function mirroredNetworkingActive(): bool
    {
        return $this->wslNetworkingMode() === 'mirrored';
    }

    /**
     * Whether ~/.wslconfig REQUESTS mirrored networking. Distinct from
     * mirroredNetworkingActive() (the RUNNING mode): the two diverge after
     * `setup` writes the config but before the user runs `wsl --shutdown`.
     */
    protected function mirroredNetworkingRequested(): bool
    {
        $path = $this->wslConfigPath();
        if ($path === null || ! is_file($path)) {
            return false;
        }

        $ini = (string) @file_get_contents($path);

        return preg_match('/^[ \t]*networkingMode[ \t]*=[ \t]*mirrored\b/mi', $ini) === 1;
    }

    /**
     * Whether mirrored networking is configured in ~/.wslconfig but isn't the
     * running mode yet — i.e. a one-time `wsl --shutdown` is still needed for it
     * to take effect. `up` uses this to re-surface that reminder so a forgotten
     * restart doesn't silently leave the churning node-IP hosts entry in place.
     */
    protected function mirroredRestartPending(): bool
    {
        return $this->isWsl()
            && ! $this->mirroredNetworkingActive()
            && $this->mirroredNetworkingRequested();
    }

    /**
     * Path to the Windows-side ~/.wslconfig, as seen from inside WSL (e.g.
     * /mnt/c/Users/<you>/.wslconfig), or null if the Windows profile can't be
     * resolved. It lives at %USERPROFILE%, NOT inside the distro.
     */
    protected function wslConfigPath(): ?string
    {
        // Run cmd.exe from a Windows cwd so it doesn't emit the "UNC paths are
        // not supported" warning it prints when the cwd is a \\wsl$ path.
        $winProfile = trim(Process::path('/mnt/c')->run('cmd.exe /c echo %USERPROFILE%')->output());
        if ($winProfile === '' || ! str_contains($winProfile, ':\\')) {
            return null;
        }

        $wslProfile = trim(Process::run('wslpath -u '.escapeshellarg($winProfile))->output());
        if ($wslProfile === '' || ! is_dir($wslProfile)) {
            return null;
        }

        return $wslProfile.'/.wslconfig';
    }

    /**
     * Whether Kubernetes is provided by Docker Desktop injected into WSL2.
     *
     * When Docker Desktop's "Enable Kubernetes" is on and its WSL2 integration
     * is active, the kube context is set to `docker-desktop` and the Docker
     * daemon is shared with the host — no separate cluster runtime is needed.
     */
    protected function isDockerDesktopKubernetesOnWsl(): bool
    {
        if (! $this->isWsl()) {
            return false;
        }

        $context = trim(Process::run('kubectl config current-context')->output());

        return $context === 'docker-desktop';
    }

    /**
     * Whether WSL can currently hand off `.exe` files to Windows (interop).
     *
     * WSL registers a `WSLInterop` binfmt_misc handler at VM boot so Linux can
     * exec Windows binaries like certutil.exe/powershell.exe. That registration
     * can go missing without the VM itself dying — e.g. after switching the
     * default distro (`wsl --set-default`) or a Windows sleep/hibernate — in
     * which case any `.exe` on PATH still resolves but fails with a bare
     * "Exec format error" when exec'd. A full `wsl --shutdown` + reopen
     * re-registers it. Only meaningful when isWsl() is true.
     */
    protected function hasWslInterop(): bool
    {
        return is_file('/proc/sys/fs/binfmt_misc/WSLInterop')
            || is_file('/proc/sys/fs/binfmt_misc/WSLInterop-late');
    }

    /**
     * Whether the Docker CLI is available on this machine.
     */
    protected function hasDockerCli(): bool
    {
        return trim(Process::run('command -v docker')->output()) !== '';
    }

    /**
     * Whether the running Docker daemon is Docker Desktop (not Colima, OrbStack,
     * native Docker Engine, etc.). Returns false if the daemon is unreachable.
     */
    protected function isDockerDesktop(): bool
    {
        $info = Process::run('docker info --format "{{.OperatingSystem}}"')->output();

        return str_contains(strtolower($info), 'docker desktop');
    }

    /**
     * On WSL2, whether Docker Desktop's daemon is reachable from inside the
     * distro (via WSL2 integration). This tells us the Docker engine is present
     * even if Kubernetes isn't enabled in Docker Desktop.
     */
    protected function hasDockerDesktopOnWsl(): bool
    {
        if (! $this->isWsl()) {
            return false;
        }

        return $this->hasDockerCli() && $this->isDockerDesktop();
    }
}
