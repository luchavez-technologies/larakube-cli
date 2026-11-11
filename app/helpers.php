<?php

if (! function_exists('home_path')) {
    /**
     * Get the path to the user's home directory. Falls back to a temp dir in the
     * rare case none of $_SERVER['HOME'], getenv('HOME'), or HOMEDRIVE+HOMEPATH
     * (Windows) resolve, so callers always get a writable path instead of "".
     */
    function home_path(string $path = ''): string
    {
        $home = $_SERVER['HOME'] ?? (getenv('HOME') ?: null);

        if (! $home) {
            $home = $_SERVER['USERPROFILE'] ?? (getenv('USERPROFILE') ?: null);
        }

        if (! $home) {
            $drive = $_SERVER['HOMEDRIVE'] ?? (getenv('HOMEDRIVE') ?: '');
            $homePath = $_SERVER['HOMEPATH'] ?? (getenv('HOMEPATH') ?: '');
            if ($drive !== '' && $homePath !== '') {
                $home = $drive.$homePath;
            }
        }

        if (! $home) {
            $home = sys_get_temp_dir();
        }

        return $home.($path !== '' ? DIRECTORY_SEPARATOR.$path : '');
    }
}
