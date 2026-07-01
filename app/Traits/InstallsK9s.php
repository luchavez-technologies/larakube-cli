<?php

namespace App\Traits;

trait InstallsK9s
{
    /**
     * Resolve the k9s binary — PATH first, then the managed location.
     */
    protected function resolveK9sBin(): ?string
    {
        $which = trim((string) shell_exec('command -v k9s 2>/dev/null'));
        if ($which !== '' && @is_executable($which)) {
            return $which;
        }

        $managed = home_path('.larakube/bin/k9s');
        if (@is_executable($managed)) {
            return $managed;
        }

        return null;
    }

    /**
     * Download and install k9s for the current platform:
     * - macOS: Homebrew
     * - Linux/WSL2: GitHub release tarball into ~/.larakube/bin (no sudo, no snap)
     */
    protected function installK9s(): bool
    {
        $version = 'v0.32.5';

        if ($this->isDarwin()) {
            $brew = trim((string) shell_exec('command -v brew 2>/dev/null'));
            if ($brew === '') {
                $this->warn('  Homebrew not found. Install it from https://brew.sh then run: brew install k9s');

                return false;
            }

            $this->laraKubeInfo('Installing k9s via Homebrew...');
            passthru('brew install k9s', $code);

            return $code === 0;
        }

        if ($this->isLinux()) {
            $machine = php_uname('m');
            $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
            $binDir = home_path('.larakube/bin');
            $bin = $binDir.'/k9s';
            @mkdir($binDir, 0755, true);

            $where = $this->isWsl() ? 'WSL2' : 'Linux';
            $this->laraKubeInfo("Installing k9s {$version} for {$where}...");

            $url = "https://github.com/derailed/k9s/releases/download/{$version}/k9s_linux_{$arch}.tar.gz";
            exec('curl -fsSL '.escapeshellarg($url).' | tar -xz -C '.escapeshellarg($binDir).' k9s 2>/dev/null', $_, $code);

            if ($code !== 0 || ! is_file($bin) || ! is_executable($bin)) {
                $this->laraKubeWarn("Failed to download k9s {$version}.");
                $this->laraKubeLine('  👉 Install manually: https://k9scli.io/topics/install/');

                return false;
            }

            @chmod($bin, 0755);

            $verOut = (string) shell_exec(escapeshellarg($bin).' version --short 2>/dev/null');
            if (str_contains(strtolower($verOut), strtolower(str_replace('v', '', $version)))) {
                $this->laraKubeInfo("✅ k9s ready at {$bin}");

                return true;
            }

            @unlink($bin);
            $this->laraKubeWarn('Installed k9s but binary verification failed.');

            return false;
        }

        $this->warn('  k9s install not supported on this platform. See https://k9scli.io/topics/install/');

        return false;
    }
}
