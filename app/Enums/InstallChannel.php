<?php

namespace App\Enums;

/**
 * Who owns an installed CLI tool's binary, and therefore who may update or
 * reinstall it. Every update/reinstall follows the channel the binary came
 * from, so the LaraKube CLI never drops a second copy next to one Homebrew,
 * apt/snap, or another installer owns (the copy first on PATH would win, and
 * the "update" would silently do nothing).
 */
enum InstallChannel: string
{
    /** Directories an OS package manager owns. */
    private const array SYSTEM_PREFIXES = ['/usr/bin/', '/usr/sbin/', '/bin/', '/sbin/', '/snap/', '/usr/lib/', '/usr/share/'];

    /**
     * @param  list<string>  $managedMarkers  path fragments (forward slashes) that mark a LaraKube-managed install for this tool
     */
    public static function detect(string $path, string $binDir, array $managedMarkers = []): self
    {
        $normalized = str_replace('\\', '/', $path);
        $real = str_replace('\\', '/', (string) (@realpath($path) ?: $path));

        foreach ([$normalized, $real] as $candidate) {
            if (str_contains($candidate, '/Cellar/') || str_contains($candidate, '/Caskroom/')
                || str_starts_with($candidate, '/opt/homebrew/') || str_starts_with($candidate, '/home/linuxbrew/')) {
                return self::Homebrew;
            }
        }

        foreach (self::SYSTEM_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return self::System;
            }
        }

        $binDir = rtrim(str_replace('\\', '/', $binDir), '/');
        $markers = ['/.larakube/', ...$managedMarkers];

        if ($binDir !== '' && str_starts_with($normalized, $binDir.'/')) {
            return self::LaraKube;
        }

        foreach ($markers as $marker) {
            if (str_contains($normalized, $marker)) {
                return self::LaraKube;
            }
        }

        return self::Other;
    }

    /** Whether the LaraKube CLI may update or reinstall a binary from this channel in place. */
    public function isManagedByCli(): bool
    {
        return $this === self::Homebrew || $this === self::LaraKube;
    }

    public function label(): string
    {
        return match ($this) {
            self::Homebrew => 'Homebrew',
            self::LaraKube => 'LaraKube CLI',
            self::System => 'your system package manager',
            self::Other => 'another installer',
        };
    }
    /** A Homebrew formula or cask: `brew upgrade` / `brew reinstall`. */
    case Homebrew = 'homebrew';

    /** Installed by the LaraKube CLI itself (~/.larakube/bin, or the tool's own vendor location the CLI installed into). */
    case LaraKube = 'larakube';

    /** The operating system's package manager (apt, dnf, snap): never touched by the CLI. */
    case System = 'system';

    /** Anything else (a manual install, Docker Desktop's bundled kubectl, …): never touched by the CLI. */
    case Other = 'other';
}
