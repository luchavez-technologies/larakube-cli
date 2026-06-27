<?php

if (! function_exists('home_path')) {
    /**
     * Get the path to the user's home directory. Falls back to a temp dir in the
     * rare case none of $_SERVER['HOME'], getenv('HOME'), or HOMEDRIVE+HOMEPATH
     * (Windows) resolve, so callers always get a writable path instead of "".
     */
    function home_path(string $path = ''): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');

        if (! $home) {
            $home = ($_SERVER['HOMEDRIVE'] ?? '').($_SERVER['HOMEPATH'] ?? '');
        }

        if (! $home) {
            $home = sys_get_temp_dir();
        }

        return $home.($path !== '' ? DIRECTORY_SEPARATOR.$path : '');
    }
}

if (! function_exists('host_uid')) {
    /**
     * Get the host user's ID.
     * Tries posix extension first, then `id -u` command, and falls back to 1000.
     */
    function host_uid(): int
    {
        if (function_exists('posix_getuid')) {
            return posix_getuid();
        }

        $id = shell_exec('id -u 2>/dev/null');
        if ($id !== null && is_numeric(trim($id))) {
            return (int) trim($id);
        }

        // Fallback to standard Linux default
        return 1000;
    }
}

if (! function_exists('host_gid')) {
    /**
     * Get the host group's ID.
     * Tries posix extension first, then `id -g` command, and falls back to 1000.
     */
    function host_gid(): int
    {
        if (function_exists('posix_getgid')) {
            return posix_getgid();
        }

        $id = shell_exec('id -g 2>/dev/null');
        if ($id !== null && is_numeric(trim($id))) {
            return (int) trim($id);
        }

        // Fallback to standard Linux default
        return 1000;
    }
}
