<?php

namespace App\Services\CliTools;

use App\Contracts\CliTools\InstallsViaHomebrew;
use App\Contracts\CliTools\RunsInWslOnWindows;
use App\Traits\CliTools\ManagesHomebrewPackage;

class GhTool extends AbstractCliTool implements InstallsViaHomebrew, RunsInWslOnWindows
{
    use ManagesHomebrewPackage;

    public function slug(): string
    {
        return 'gh';
    }

    public function label(): string
    {
        return 'GitHub CLI (gh)';
    }

    public function description(): string
    {
        return 'Native GitHub Actions and GHCR container registry management';
    }

    /**
     * Shell that puts the current GitHub CLI into $binDir. The version is read from GitHub's own
     * `/releases/latest` redirect (no API call to rate-limit), so there is no tag here to go stale.
     */
    public static function ghInstallCommand(string $arch, string $binDir, string $os = 'linux'): string
    {
        if ($os === 'darwin' || $os === 'mac') {
            return 'set -e; U=$(curl -fsSLI -o /dev/null -w "%{url_effective}" https://github.com/cli/cli/releases/latest); V="${U##*/v}"; '
                .'[ "$V" != "$U" ] || { echo "Could not find the latest GitHub CLI release." >&2; exit 1; }; '
                .'D="gh_${V}_macOS_'.$arch.'"; T=$(mktemp -d); '
                .'curl -fsSL "https://github.com/cli/cli/releases/download/v${V}/${D}.zip" -o "$T/gh.zip"; '
                .'unzip -q -j "$T/gh.zip" "${D}/bin/gh" -d '.escapeshellarg($binDir).'; rm -rf "$T"';
        }

        return 'set -e; U=$(curl -fsSLI -o /dev/null -w "%{url_effective}" https://github.com/cli/cli/releases/latest); V="${U##*/v}"; '
            .'[ "$V" != "$U" ] || { echo "Could not find the latest GitHub CLI release." >&2; exit 1; }; '
            .'D="gh_${V}_linux_'.$arch.'"; '
            .'curl -fsSL "https://github.com/cli/cli/releases/download/v${V}/${D}.tar.gz" | tar -xz -C '.escapeshellarg($binDir).' --strip-components=2 "${D}/bin/gh"';
    }

    protected function homebrewPackage(): string
    {
        return 'gh';
    }

    protected function installOnUnix(): bool
    {
        return $this->downloadIntoBinDir(self::ghInstallCommand(self::hostArch(), self::binDir(), self::hostOs()), 'gh');
    }
}
