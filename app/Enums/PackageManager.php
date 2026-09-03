<?php

namespace App\Enums;

use App\Contracts\HasCommandOptions;
use App\Contracts\HasLabel;
use App\Contracts\HasSelectOptions;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum PackageManager: string implements HasCommandOptions, HasLabel, HasSelectOptions
{
    use ProvidesCommandOptions, ProvidesSelectOptions;

    public function getLabel(): ?string
    {
        return match ($this) {
            self::NPM => 'NPM',
            self::PNPM => 'PNPM',
            self::BUN => 'Bun',
            self::YARN => 'Yarn',
        };
    }

    public function installCommand(): string
    {
        return "{$this->value} install";
    }

    public function addDevCommand(array $packages): string
    {
        $packagesStr = implode(' ', $packages);

        return match ($this) {
            self::YARN => "yarn add --dev {$packagesStr}",
            self::PNPM => "pnpm add --save-dev {$packagesStr}",
            self::BUN => "bun add --dev {$packagesStr}",
            default => "npm install --save-dev {$packagesStr}",
        };
    }

    public function buildCommand(): string
    {
        return match ($this) {
            self::YARN => 'yarn build',
            default => "{$this->value} run build",
        };
    }

    public function buildSsrCommand(): string
    {
        return match ($this) {
            self::YARN => 'yarn build:ssr',
            default => "{$this->value} run build:ssr",
        };
    }

    public function getSsrStartCommand(): string
    {
        return '["node", "bootstrap/ssr/ssr.js"]';
    }

    /**
     * Invoke a named package script. devCommand() hardcodes "dev", which is not
     * universal — create-docusaurus emits no `dev` script at all, so `npm run
     * dev` there fails with "Missing script" before anything starts.
     */
    public function runScript(string $script): string
    {
        return match ($this) {
            self::YARN, self::PNPM => "{$this->value} {$script}",
            self::NPM => "npm run {$script} --",
            default => "{$this->value} run {$script}",
        };
    }

    public function devCommand(): string
    {
        return match ($this) {
            self::YARN, self::PNPM => "{$this->value} dev",
            self::NPM => 'npm run dev --',
            default => "{$this->value} run dev",
        };
    }

    public function getReadinessProbeCommand(): string
    {
        $separator = ($this === self::NPM) ? '' : ' --';

        return '["sh", "-c", "'.$this->devCommand().$separator.' --host 0.0.0.0"]';
    }

    public function getOptionFlag(): string
    {
        return "--$this->value";
    }

    case NPM = 'npm';
    case PNPM = 'pnpm';
    case BUN = 'bun';
    case YARN = 'yarn';
}
