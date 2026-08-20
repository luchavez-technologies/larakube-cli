<?php

namespace App\Traits;

trait InteractsWithJsonFile
{
    /** Read and decode a JSON file into an array, or null if missing/invalid. */
    protected static function readJsonFile(string $path): ?array
    {
        // is_file(), not file_exists(): the latter is also true for a
        // directory, and file_get_contents() on one is an uncaught
        // engine-level error, not a graceful false/null — confirmed live
        // (2026-08-20) when a stray directory literally named .larakube.json
        // crashed every caller of this trait (ConfigData::loadFromFile(),
        // GlobalConfigData::load()) with "Read of N bytes failed... Is a
        // directory" instead of the "missing/invalid" null this already
        // handles for every other bad-path case.
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($data) ? $data : null;
    }

    /** Write to a temp file in the same directory then rename, so a crash mid-write can't leave the file truncated/corrupt. */
    protected static function atomicWriteJson(string $path, array $data, ?int $mode = null): void
    {
        $tmp = $path.'.tmp'.getmypid();
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($mode !== null) {
            @chmod($tmp, $mode);
        }

        rename($tmp, $path);
    }
}
