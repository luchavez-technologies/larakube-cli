<?php

namespace App\Support;

/**
 * The path to hand `ssh -i` on this side of a Windows machine.
 *
 * `cloud:create` runs on the Windows host, so the key a server trusts is a
 * Windows file, recorded as a Windows path (`C:\Users\you\.ssh\id_ed25519`)
 * in the stack and the project's context. Project commands run inside the
 * larakube-ubuntu distro, where that path means nothing. This translates it
 * to `/mnt/c/...` and makes sure ssh will accept it: OpenSSH refuses a
 * private key others can read, and a Windows-drive file reads as 0777 unless
 * the drive is mounted with `metadata` (the image's wsl.conf does that, so a
 * one-time chmod 600 sticks). Where it does not stick, a private 0600 copy
 * under ~/.ssh/larakube-host/ is used instead, refreshed when the key changes.
 *
 * The Windows file stays the one key; nothing here changes it beyond its
 * WSL-side permission bits, which Windows' own OpenSSH ignores.
 */
final class SshKeyPath
{
    public static function forThisSide(string $key, string $mountRoot = '/mnt'): string
    {
        if (Os::isWindows() || preg_match('#^([A-Za-z]):[\\\\/](.*)$#', $key, $drive) !== 1) {
            return $key;
        }

        $mounted = rtrim($mountRoot, '/').'/'.strtolower($drive[1]).'/'.str_replace('\\', '/', $drive[2]);

        if (! is_file($mounted)) {
            return $key;
        }

        if (self::isPrivate($mounted)) {
            return $mounted;
        }

        @chmod($mounted, 0600);
        clearstatcache(true, $mounted);

        if (self::isPrivate($mounted)) {
            return $mounted;
        }

        return self::privateCopy($key, $mounted) ?? $mounted;
    }

    private static function isPrivate(string $path): bool
    {
        $permissions = @fileperms($path);

        return $permissions !== false && ($permissions & 0077) === 0;
    }

    private static function privateCopy(string $key, string $mounted): ?string
    {
        $dir = home_path('.ssh/larakube-host');
        $copy = $dir.'/'.substr(sha1($key), 0, 8).'-'.basename($mounted);

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $stale = ! is_file($copy) || @filesize($copy) !== @filesize($mounted) || @filemtime($copy) < @filemtime($mounted);

        if ($stale && ! @copy($mounted, $copy)) {
            return null;
        }

        @chmod($copy, 0600);

        return $copy;
    }
}
