<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

trait InstallsK9s
{
    /**
     * Resolve the k9s binary — PATH first, then the managed location.
     */
    protected function resolveK9sBin(): ?string
    {
        $which = trim(Process::run('command -v k9s')->output());
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
     * - macOS: tries Homebrew if available, otherwise direct binary tarball
     * - Linux/WSL2: direct GitHub release tarball into ~/.larakube/bin (no sudo, no snap)
     */
    protected function installK9s(): bool
    {
        if ($this->isDarwin() && trim(Process::run('command -v brew')->output()) !== '') {
            $this->laraKubeInfo('Installing k9s via Homebrew...');
            $code = Process::forever()->run('brew install k9s', function (string $type, string $output): void {
                echo $output;
            })->exitCode();

            if ($code === 0 && $this->resolveK9sBin() !== null) {
                return true;
            }
        }

        $machine = php_uname('m');
        $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $targetOs = $this->isDarwin() ? 'Darwin' : 'Linux';
        $binDir = home_path('.larakube/bin');
        $bin = $binDir.'/k9s';
        @mkdir($binDir, 0755, true);

        $platformName = $this->isDarwin() ? 'macOS' : ($this->isWsl() ? 'WSL2' : 'Linux');
        $this->laraKubeInfo("Downloading k9s standalone binary for {$platformName} ({$arch})...");

        $url = "https://github.com/derailed/k9s/releases/latest/download/k9s_{$targetOs}_{$arch}.tar.gz";
        $code = Process::forever()->run('curl -fsSL '.escapeshellarg($url).' | tar -xz -C '.escapeshellarg($binDir).' k9s')->exitCode();

        if ($code !== 0 || ! is_file($bin) || ! is_executable($bin)) {
            $this->laraKubeWarn('Failed to download k9s.');
            $this->laraKubeLine('  👉 Install manually: https://k9scli.io/topics/install/');

            return false;
        }

        @chmod($bin, 0755);

        $verOut = Process::run(escapeshellarg($bin).' version --short')->output();
        if ($verOut !== '') {
            $this->laraKubeInfo("✅ k9s ready at {$bin}");

            return true;
        }

        @unlink($bin);
        $this->laraKubeWarn('Installed k9s but binary verification failed.');

        return false;
    }
}
