<?php

namespace App\Enums;

use App\Contracts\CliTools\InstallsOnWindows;
use App\Services\CliTools\AbstractCliTool;
use App\Services\CliTools\AwsTool;
use App\Services\CliTools\GcloudTool;
use App\Services\CliTools\GhTool;
use App\Services\CliTools\HcloudTool;
use App\Services\CliTools\K9sTool;
use App\Services\CliTools\KubectlTool;
use App\Services\CliTools\TeaTool;
use App\Services\CliTools\TofuTool;

/**
 * The CLI tools `larakube setup` manages. Each case is backed by its own
 * service class (app/Services/CliTools), which owns resolution and the
 * install/update/reinstall flow; this enum is the stable name every command
 * and test already uses.
 */
enum CliTool: string
{
    public function service(): AbstractCliTool
    {
        return match ($this) {
            self::KUBECTL => new KubectlTool,
            self::K9S => new K9sTool,
            self::TOFU => new TofuTool,
            self::GCLOUD => new GcloudTool,
            self::AWS => new AwsTool,
            self::HCLOUD => new HcloudTool,
            self::GH => new GhTool,
            self::TEA => new TeaTool,
        };
    }

    public function label(): string
    {
        return $this->service()->label();
    }

    public function description(): string
    {
        return $this->service()->description();
    }

    public function binary(): string
    {
        return $this->service()->binary();
    }

    public function isDefault(): bool
    {
        return $this->service()->isDefault();
    }

    /** Whether a verified native Windows installer exists (no WSL round-trip). */
    public function installsOnWindows(): bool
    {
        return $this->service() instanceof InstallsOnWindows;
    }

    public function isInstallableHere(): bool
    {
        return $this->service()->isInstallableHere();
    }

    public static function binDir(): string
    {
        return AbstractCliTool::binDir();
    }

    /** @return list<string> */
    public function candidatePaths(): array
    {
        return $this->service()->candidatePaths();
    }

    public function resolveBinary(): ?string
    {
        return $this->service()->resolveBinary();
    }

    public function isInstalled(): bool
    {
        return $this->service()->isInstalled();
    }

    public function channel(): ?InstallChannel
    {
        return $this->service()->channel();
    }

    public function install(): bool
    {
        return $this->service()->install();
    }

    public function update(): bool
    {
        return $this->service()->update();
    }

    public function reinstall(): bool
    {
        return $this->service()->reinstall();
    }

    /** @return array{slug: string, label: string, binary: string, default: bool, installed: bool, path: ?string, channel: ?string, managed: bool, installable: bool, version: ?string} */
    public function status(): array
    {
        return $this->service()->status();
    }

    public function ensureAuth(bool $prompt = true): bool
    {
        return $this->service()->ensureAuth($prompt);
    }

    public function lastError(): ?string
    {
        return $this->service()->lastError();
    }

    public static function ghInstallCommand(string $arch, string $binDir, string $os = 'linux'): string
    {
        return GhTool::ghInstallCommand($arch, $binDir, $os);
    }

    public static function teaInstallCommand(string $arch, string $target, string $os = 'linux'): string
    {
        return TeaTool::teaInstallCommand($arch, $target, $os);
    }

    public static function kubectlInstallCommand(string $os, string $arch, string $binDir): string
    {
        return KubectlTool::kubectlInstallCommand($os, $arch, $binDir);
    }

    public static function k9sInstallCommand(string $os, string $arch, string $binDir): string
    {
        return K9sTool::k9sInstallCommand($os, $arch, $binDir);
    }

    public static function tofuInstallCommand(string $os, string $arch, string $binDir): string
    {
        return TofuTool::tofuInstallCommand($os, $arch, $binDir);
    }

    public static function awsInstallCommand(string $binDir): string
    {
        return AwsTool::awsInstallCommand($binDir);
    }

    public static function awsUpdateCommand(string $binDir): string
    {
        return AwsTool::awsUpdateCommand($binDir);
    }

    public static function hcloudInstallCommand(string $os, string $arch, string $binDir): string
    {
        return HcloudTool::hcloudInstallCommand($os, $arch, $binDir);
    }

    public static function pythonStandaloneDir(): string
    {
        return GcloudTool::pythonStandaloneDir();
    }

    public static function pythonStandaloneInstallCommand(string $arch, string $installDir): string
    {
        return GcloudTool::pythonStandaloneInstallCommand($arch, $installDir);
    }
    case KUBECTL = 'kubectl';
    case K9S = 'k9s';
    case TOFU = 'tofu';
    case GCLOUD = 'gcloud';
    case AWS = 'aws';
    case HCLOUD = 'hcloud';
    case GH = 'gh';
    case TEA = 'tea';
}
