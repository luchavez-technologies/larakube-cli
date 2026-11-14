<?php

namespace App\Contracts\CliTools;

/**
 * A CLI tool Homebrew can install on macOS. The base tool class runs these
 * commands for the Homebrew channel, so each tool only names its package.
 */
interface InstallsViaHomebrew
{
    public function homebrewInstallCommand(): string;

    public function homebrewUpgradeCommand(): string;

    public function homebrewReinstallCommand(): string;

    /**
     * Whether a failed `brew install` may fall back to the LaraKube CLI's own
     * download (only when Homebrew left nothing installed, so no second copy).
     */
    public function fallsBackToDownloadWhenHomebrewFails(): bool;
}
