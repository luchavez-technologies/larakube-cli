<?php

namespace App\Services\CliTools;

use App\Contracts\CliTools\InstallsOnWindows;
use App\Contracts\CliTools\InstallsViaHomebrew;
use App\Traits\CliTools\ManagesHomebrewPackage;
use Illuminate\Support\Facades\Http;

class KubectlTool extends AbstractCliTool implements InstallsOnWindows, InstallsViaHomebrew
{
    use ManagesHomebrewPackage;

    public function slug(): string
    {
        return 'kubectl';
    }

    public function label(): string
    {
        return 'kubectl (Kubernetes CLI)';
    }

    public function description(): string
    {
        return 'The Kubernetes client every cluster command shells out to — required, not optional';
    }

    public function isDefault(): bool
    {
        return true;
    }

    public function versionArguments(): array
    {
        return ['version', '--client'];
    }

    /**
     * kubectl's official static binary for Windows — no installer, no admin,
     * downloaded with Laravel's HTTP client rather than shelling to curl
     * (there is no bash on a native Windows host to run the Darwin/Linux
     * one-liner above against).
     */
    public function installOnWindows(): bool
    {
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $version = trim((string) Http::timeout(30)->get('https://dl.k8s.io/release/stable.txt')->body());
        if ($version === '') {
            return false;
        }

        $url = "https://dl.k8s.io/release/{$version}/bin/windows/amd64/kubectl.exe";

        return $this->httpDownloadToFile($url, "{$binDir}/{$this->binary()}") && $this->isInstalledAfterSettle();
    }

    public static function kubectlInstallCommand(string $os, string $arch, string $binDir): string
    {
        $target = "{$binDir}/kubectl";

        return 'set -e; V=$(curl -fsSL https://dl.k8s.io/release/stable.txt); T=$(mktemp -d); '
            ."curl -fsSL -o \"\$T/kubectl\" \"https://dl.k8s.io/release/\$V/bin/{$os}/{$arch}/kubectl\"; "
            .'install -m 0755 "$T/kubectl" '.escapeshellarg($target).'; rm -rf "$T"';
    }

    protected function homebrewPackage(): string
    {
        return 'kubernetes-cli';
    }

    protected function unixCandidatePaths(): array
    {
        return ['/usr/bin/kubectl', '/snap/bin/kubectl'];
    }

    /**
     * kubectl is NOT optional: every cluster command shells out to a bare
     * `kubectl` (see Kubectl::prefix()). It installs to ~/.larakube/bin (or
     * LARAKUBE_BIN_DIR) without sudo, so non-devs never see password prompts.
     */
    protected function installOnUnix(): bool
    {
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        return $this->runStreaming(self::kubectlInstallCommand(self::hostOs(), self::hostArch(), $binDir)) === 0 && $this->isInstalled();
    }
}
