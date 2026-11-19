<?php

namespace App\Services\CliTools;

use App\Contracts\CliTools\InstallsOnWindows;
use App\Contracts\CliTools\InstallsViaHomebrew;
use App\Traits\CliTools\ManagesHomebrewPackage;
use Illuminate\Support\Facades\Http;

class TofuTool extends AbstractCliTool implements InstallsOnWindows, InstallsViaHomebrew
{
    use ManagesHomebrewPackage;

    public const MIN_S3_LOCKFILE_VERSION = '1.10.0';

    public function slug(): string
    {
        return 'tofu';
    }

    public function label(): string
    {
        return 'OpenTofu (Infrastructure Provisioner)';
    }

    public function description(): string
    {
        return 'Infrastructure-as-Code provisioner for cloud:create (VPS & Managed)';
    }

    public function isDefault(): bool
    {
        return true;
    }

    public function versionArguments(): array
    {
        return ['version'];
    }

    /**
     * OpenTofu's Windows asset filename embeds the version, so (unlike k9s)
     * there is no "latest/download" shortcut — the current tag is resolved
     * from GitHub's API first.
     */
    public function installOnWindows(): bool
    {
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $release = Http::timeout(30)->get('https://api.github.com/repos/opentofu/opentofu/releases/latest')->json();
        $version = ltrim((string) ($release['tag_name'] ?? ''), 'v');
        if ($version === '') {
            return false;
        }

        $url = "https://github.com/opentofu/opentofu/releases/download/v{$version}/tofu_{$version}_windows_amd64.zip";

        return $this->httpDownloadZipMember($url, "{$binDir}/{$this->binary()}", 'tofu.exe') && $this->isInstalledAfterSettle();
    }

    public static function tofuInstallCommand(string $os, string $arch, string $binDir): string
    {
        return 'set -e; U=$(curl -fsSLI -o /dev/null -w "%{url_effective}" https://github.com/opentofu/opentofu/releases/latest); V="${U##*/v}"; '
            .'[ "$V" != "$U" ] || { echo "Could not find the latest OpenTofu release." >&2; exit 1; }; '
            .'curl -fsSL "https://github.com/opentofu/opentofu/releases/download/v${V}/tofu_${V}_'.$os.'_'.$arch.'.tar.gz" | tar -xz -C '.escapeshellarg($binDir).' tofu';
    }

    protected function homebrewPackage(): string
    {
        return 'opentofu';
    }

    /** An existing terraform binary satisfies OpenTofu on Darwin/Linux. */
    protected function alternateBinaries(): array
    {
        return ['terraform'];
    }

    protected function unixCandidatePaths(): array
    {
        $binDir = self::binDir();

        return [
            '/opt/homebrew/bin/terraform',
            '/usr/local/bin/terraform',
            '/home/linuxbrew/.linuxbrew/bin/terraform',
            "{$binDir}/terraform",
        ];
    }

    /** OpenTofu's release archive, unpacked straight into binDir without sudo or unzip. */
    protected function installOnUnix(): bool
    {
        return $this->downloadIntoBinDir(self::tofuInstallCommand(self::hostOs(), self::hostArch(), self::binDir()), 'tofu');
    }
}
