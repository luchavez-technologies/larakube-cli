<?php

namespace App\Services\CliTools;

use App\Traits\InteractsWithJsonFile;

/**
 * Where each CLI tool lives on this side, and its version:
 * `~/.larakube/tools.json`. A tool's location and version change only when
 * it is installed, updated, replaced or removed, so they are recorded once
 * and re-checked with a stat (the binary's real path, size and mtime), never
 * re-resolved or re-probed on every run. LaraKube Desktop reads the same
 * file instead of asking the CLI.
 *
 * Its own file, never config.json: GlobalConfigData::save() rewrites
 * accounts, tokens and tofu passphrases from each process's in-memory copy,
 * and a tool-path save from one of many parallel CLI runs must never be able
 * to clobber those. Here a race only loses a cache entry, which heals on the
 * next lookup. Each side (Windows host, larakube-ubuntu distro) keeps its
 * own: a path only means something where it was resolved.
 */
class ToolPaths
{
    use InteractsWithJsonFile;

    public const string FILE = 'tools.json';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $entries = null;

    public function path(): string
    {
        return home_path('.larakube/'.self::FILE);
    }

    /**
     * @return array{path: string, real: string, size: int, mtime: int, version: ?string, channel: ?string}|null
     */
    public function entry(string $slug): ?array
    {
        $entry = $this->all()[$slug] ?? null;

        if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
            return null;
        }

        return [
            'path' => $entry['path'],
            'real' => is_string($entry['real'] ?? null) ? $entry['real'] : $entry['path'],
            'size' => (int) ($entry['size'] ?? 0),
            'mtime' => (int) ($entry['mtime'] ?? 0),
            'version' => is_string($entry['version'] ?? null) ? $entry['version'] : null,
            'channel' => is_string($entry['channel'] ?? null) ? $entry['channel'] : null,
        ];
    }

    /**
     * Record a tool at $path. A version is kept only while the binary's
     * fingerprint is unchanged; a different binary at the same path drops it.
     */
    public function remember(string $slug, string $path, ?string $version = null, ?string $channel = null): void
    {
        $fingerprint = self::fingerprint($path);
        $previous = $this->entry($slug);

        if ($version === null && $previous !== null && $previous['path'] === $path && self::matches($previous, $fingerprint)) {
            $version = $previous['version'];
        }

        $this->write($slug, [
            'path' => $path,
            ...$fingerprint,
            'version' => $version,
            'channel' => $channel ?? $previous['channel'] ?? null,
        ]);
    }

    public function forget(string $slug): void
    {
        if ($this->entry($slug) !== null) {
            $this->write($slug, null);
        }
    }

    /**
     * The recorded version, only while the binary is the one it was read from.
     */
    public function version(string $slug, string $path): ?string
    {
        $entry = $this->entry($slug);

        if ($entry === null || $entry['path'] !== $path || ! self::matches($entry, self::fingerprint($path))) {
            return null;
        }

        return $entry['version'];
    }

    /**
     * @return array{real: string, size: int, mtime: int}
     */
    public static function fingerprint(string $path): array
    {
        $real = @realpath($path) ?: $path;

        return [
            'real' => $real,
            'size' => (int) (@filesize($real) ?: 0),
            'mtime' => (int) (@filemtime($real) ?: 0),
        ];
    }

    /**
     * @param  array{real: string, size: int, mtime: int}  $entry
     * @param  array{real: string, size: int, mtime: int}  $fingerprint
     */
    private static function matches(array $entry, array $fingerprint): bool
    {
        return $entry['real'] === $fingerprint['real']
            && $entry['size'] === $fingerprint['size']
            && $entry['mtime'] === $fingerprint['mtime'];
    }

    /** @return array<string, array<string, mixed>> */
    private function all(): array
    {
        return $this->entries ??= (self::readJsonFile($this->path()) ?? []);
    }

    /**
     * Re-read before writing so entries other processes recorded meanwhile
     * survive, and skip the write entirely when nothing changed.
     *
     * @param  array<string, mixed>|null  $entry
     */
    private function write(string $slug, ?array $entry): void
    {
        $current = self::readJsonFile($this->path()) ?? [];

        if (($current[$slug] ?? null) === $entry) {
            $this->entries = $current;

            return;
        }

        if ($entry === null) {
            unset($current[$slug]);
        } else {
            $current[$slug] = $entry;
        }

        $dir = dirname($this->path());

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        self::atomicWriteJson($this->path(), $current, 0600);
        $this->entries = $current;
    }
}
