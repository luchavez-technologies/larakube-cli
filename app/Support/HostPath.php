<?php

namespace App\Support;

/**
 * A file path from the shared ~/.larakube/config.json, made usable on this
 * side of a Windows machine.
 *
 * Windows and LaraKube Linux share that file, so a path saved on one side is
 * read on the other: `C:\Users\you\key.json` from the host means nothing
 * inside WSL, and `/mnt/c/Users/you/key.json` from WSL means nothing on
 * Windows. A drive path is translated to the other side's form only when the
 * file exists there; anything else (a native path, inline JSON, a missing
 * file) comes back unchanged, so a caller's own "not found" handling still
 * sees the stored value.
 */
final class HostPath
{
    public static function forThisSide(string $path, string $mountRoot = '/mnt'): string
    {
        $translated = Os::isWindows()
            ? self::toWindows($path, $mountRoot)
            : self::toWsl($path, $mountRoot);

        return $translated !== null && file_exists($translated) ? $translated : $path;
    }

    /** `C:\Users\you\key.json` → `/mnt/c/Users/you/key.json`; null for anything that is not a drive path. */
    public static function toWsl(string $path, string $mountRoot = '/mnt'): ?string
    {
        if (preg_match('#^([A-Za-z]):[\\\\/](.*)$#', $path, $drive) !== 1) {
            return null;
        }

        return rtrim($mountRoot, '/').'/'.strtolower($drive[1]).'/'.str_replace('\\', '/', $drive[2]);
    }

    /** `/mnt/c/Users/you/key.json` → `C:\Users\you\key.json`; null for anything not under a mounted drive. */
    public static function toWindows(string $path, string $mountRoot = '/mnt'): ?string
    {
        $root = preg_quote(rtrim($mountRoot, '/'), '#');

        if (preg_match('#^'.$root.'/([a-z])/(.*)$#', $path, $drive) !== 1) {
            return null;
        }

        return strtoupper($drive[1]).':\\'.str_replace('/', '\\', $drive[2]);
    }
}
