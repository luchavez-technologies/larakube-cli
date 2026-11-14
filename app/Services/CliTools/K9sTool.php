<?php

namespace App\Services\CliTools;

use App\Contracts\CliTools\InstallsOnWindows;
use App\Contracts\CliTools\InstallsViaHomebrew;
use App\Traits\CliTools\ManagesHomebrewPackage;

class K9sTool extends AbstractCliTool implements InstallsOnWindows, InstallsViaHomebrew
{
    use ManagesHomebrewPackage;

    public function slug(): string
    {
        return 'k9s';
    }

    public function label(): string
    {
        return 'k9s (Kubernetes Terminal UI)';
    }

    public function description(): string
    {
        return 'Terminal UI for browsing and managing Kubernetes clusters';
    }

    public function isDefault(): bool
    {
        return true;
    }

    public function versionArguments(): array
    {
        return ['version', '--short'];
    }

    /**
     * k9s's Windows release is a zip, unlike the plain binary tarball Darwin
     * and Linux ship — extract just the exe, same end state as the Unix
     * `tar -xz` path above.
     */
    public function installOnWindows(): bool
    {
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $url = 'https://github.com/derailed/k9s/releases/latest/download/k9s_Windows_amd64.zip';

        return $this->httpDownloadZipMember($url, "{$binDir}/{$this->binary()}", 'k9s.exe') && $this->isInstalledAfterSettle();
    }

    public static function k9sInstallCommand(string $os, string $arch, string $binDir): string
    {
        $targetOs = ($os === 'darwin' || $os === 'mac') ? 'Darwin' : 'Linux';

        return 'set -e; U="https://github.com/derailed/k9s/releases/latest/download/k9s_'.$targetOs.'_'.$arch.'.tar.gz"; '
            .'curl -fsSL "$U" | tar -xz -C '.escapeshellarg($binDir).' k9s';
    }

    protected function homebrewPackage(): string
    {
        return 'k9s';
    }

    protected function installOnUnix(): bool
    {
        return $this->downloadIntoBinDir(self::k9sInstallCommand(self::hostOs(), self::hostArch(), self::binDir()), 'k9s');
    }
}
