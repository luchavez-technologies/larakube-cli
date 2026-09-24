<?php

namespace App\Commands\Github;

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use LaravelZero\Framework\Commands\Command;

class GhCommand extends Command
{
    use InteractsWithGlobalConfig, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'gh {args?*}';

    protected $description = 'Run any gh CLI command (uses your local gh if installed, Docker otherwise)';

    public function handle(): int
    {
        // {args?*} doesn't capture flags, so pull directly from argv.
        $argv = $_SERVER['argv'] ?? [];
        $ghIdx = array_search('gh', $argv);
        $passthrough = $ghIdx !== false
            ? implode(' ', array_map('escapeshellarg', array_slice($argv, $ghIdx + 1)))
            : '';

        if ($passthrough === '') {
            // No sub-command — print gh help so the user knows what's available.
            $passthrough = '--help';
        }

        $gh = $this->getGhCommand(interactive: true);
        $code = $this->runInteractive("{$gh} {$passthrough}");

        return $code;
    }
}
