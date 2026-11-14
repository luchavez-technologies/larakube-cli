<?php

namespace App\Traits\CliTools;

/**
 * Default InstallsViaHomebrew implementation for a tool that is one Homebrew
 * formula (or cask): the tool names its package, this builds the commands.
 */
trait ManagesHomebrewPackage
{
    public function homebrewInstallCommand(): string
    {
        return 'brew install '.$this->homebrewTarget();
    }

    public function homebrewUpgradeCommand(): string
    {
        return 'brew upgrade '.$this->homebrewTarget();
    }

    public function homebrewReinstallCommand(): string
    {
        return 'brew reinstall '.$this->homebrewTarget();
    }

    public function fallsBackToDownloadWhenHomebrewFails(): bool
    {
        return true;
    }

    abstract protected function homebrewPackage(): string;

    protected function homebrewIsCask(): bool
    {
        return false;
    }

    private function homebrewTarget(): string
    {
        return ($this->homebrewIsCask() ? '--cask ' : '').$this->homebrewPackage();
    }
}
