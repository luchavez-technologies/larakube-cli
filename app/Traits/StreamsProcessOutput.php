<?php

namespace App\Traits;

use App\State;
use Illuminate\Support\Facades\Process;

/**
 * Shared by every trait that used to shell out via passthru() for a
 * long-running, user-visible command (docker build, git clone, composer
 * install, brew install, an SSH-streamed remote script, …). Kept as ONE
 * trait — rather than each caller defining its own copy of the same method —
 * so composing two of them into the same class (e.g. UpCommand pulls in both
 * InteractsWithDocker and, transitively, InteractsWithTrust) never collides;
 * PHP only flags a conflict between two DIFFERENT traits declaring the same
 * method, not the same trait reached via two paths.
 */
trait StreamsProcessOutput
{
    /**
     * Run a command with output streamed live to the terminal (matching
     * passthru()'s user-visible behavior) via the Process facade, so
     * build/install/clone commands stay fakeable in tests. No timeout by
     * default — these can legitimately run for minutes, and passthru()
     * never had one either. $timeoutSeconds bounds it for commands with
     * their own built-in timeout flag (e.g. `kubectl rollout status
     * --timeout=180s`), where 0 (unbounded) would otherwise race it. $env is
     * merged over the inherited environment via Process::env(). Under JSON
     * mode the stream goes to stderr — stdout is reserved for the JSON result.
     *
     * @param  array<string, string>  $env
     */
    protected function runStreaming(string $command, int $timeoutSeconds = 0, array $env = []): int
    {
        $process = $timeoutSeconds > 0 ? Process::timeout($timeoutSeconds) : Process::forever();

        if ($env !== []) {
            $process = $process->env($env);
        }

        return $process->run($command, function (string $type, string $output): void {
            State::$jsonMode ? fwrite(STDERR, $output) : print $output;
        })->exitCode();
    }
}
