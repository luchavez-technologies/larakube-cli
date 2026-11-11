<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/** Opens a URL in the user's default browser, the same way across every command that needs it. */
final class BrowserOpener
{
    public static function open(string $url): bool
    {
        if (\App\Support\Os::isWindows()) {
            return Process::run('start "" '.escapeshellarg($url))->successful();
        }

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            default => 'xdg-open',
        };

        return Process::run($command.' '.escapeshellarg($url))->successful();
    }
}
