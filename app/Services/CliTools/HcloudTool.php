<?php

namespace App\Services\CliTools;

use App\Contracts\CliTools\InstallsViaHomebrew;
use App\Traits\CliTools\ManagesHomebrewPackage;

class HcloudTool extends AbstractCliTool implements InstallsViaHomebrew
{
    use ManagesHomebrewPackage;

    public function slug(): string
    {
        return 'hcloud';
    }

    public function label(): string
    {
        return 'Hetzner Cloud CLI (hcloud)';
    }

    public function description(): string
    {
        return 'CLI for Hetzner Cloud servers, networks, and firewalls';
    }

    public function versionArguments(): array
    {
        return ['version'];
    }

    public static function hcloudInstallCommand(string $os, string $arch, string $binDir): string
    {
        $targetOs = ($os === 'darwin' || $os === 'mac') ? 'darwin' : 'linux';
        $url = "https://github.com/hetznercloud/cli/releases/latest/download/hcloud-{$targetOs}-{$arch}.tar.gz";

        return 'curl -fsSL '.escapeshellarg($url).' | tar -xz -C '.escapeshellarg($binDir).' hcloud';
    }

    protected function homebrewPackage(): string
    {
        return 'hcloud';
    }

    protected function installOnUnix(): bool
    {
        return $this->downloadIntoBinDir(self::hcloudInstallCommand(self::hostOs(), self::hostArch(), self::binDir()), 'hcloud');
    }
}
