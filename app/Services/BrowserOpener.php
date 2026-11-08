<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/** Opens a URL in the user's default browser, the same way across every command that needs it. */
final class BrowserOpener
{
    public static function open(string $url): bool
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        return Process::run($command.' '.escapeshellarg($url))->successful();
    }
}
