<?php

namespace App\Services\CliTools;

use App\Contracts\CliTools\InstallsViaHomebrew;
use App\Traits\CliTools\ManagesHomebrewPackage;

class TeaTool extends AbstractCliTool implements InstallsViaHomebrew
{
    use ManagesHomebrewPackage;

    /** Used only when Gitea's API cannot be reached, so a flaky lookup does not stop an install. */
    private const string TEA_FALLBACK_VERSION = '0.16.0';

    public function slug(): string
    {
        return 'tea';
    }

    public function label(): string
    {
        return 'Tea CLI (Forgejo / Gitea)';
    }

    public function description(): string
    {
        return 'Native Forgejo and Gitea git forge management';
    }

    /** Shell that downloads the current tea to $target; the version is the latest release Gitea reports. */
    public static function teaInstallCommand(string $arch, string $target, string $os = 'linux'): string
    {
        $osName = ($os === 'darwin' || $os === 'mac') ? 'darwin' : 'linux';

        return 'set -e; V=$(curl -fsSL https://gitea.com/api/v1/repos/gitea/tea/releases/latest | grep -o \'"tag_name":"[^"]*"\' | head -1 | cut -d\'"\' -f4); V="${V#v}"; '
            .'[ -n "$V" ] || V='.self::TEA_FALLBACK_VERSION.'; '
            .'curl -fsSL -o '.escapeshellarg($target).' "https://dl.gitea.com/tea/${V}/tea-${V}-'.$osName.'-'.$arch.'"';
    }

    protected function homebrewPackage(): string
    {
        return 'tea';
    }

    protected function installOnUnix(): bool
    {
        return $this->downloadIntoBinDir(self::teaInstallCommand(self::hostArch(), self::binDir().'/tea', self::hostOs()), 'tea');
    }
}
