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
            self::KUBECTL => app(KubectlTool::class),
            self::K9S => app(K9sTool::class),
            self::TOFU => app(TofuTool::class),
            self::GCLOUD => app(GcloudTool::class),
            self::AWS => app(AwsTool::class),
            self::HCLOUD => app(HcloudTool::class),
            self::GH => app(GhTool::class),
            self::TEA => app(TeaTool::class),
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

    public function isAvailable(): bool
    {
        return $this->service()->isAvailable();
    }

    /** @param  list<string>  $forwardEnv */
    public function command(array $forwardEnv = []): string
    {
        return $this->service()->command($forwardEnv);
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
    public function status(bool $fresh = false): array
    {
        return $this->service()->status($fresh);
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
