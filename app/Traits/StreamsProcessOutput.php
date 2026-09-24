<?php

namespace App\Traits;

use App\Facades\State;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

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
            State::isJsonMode() ? fwrite(STDERR, $output) : print $output;
        })->exitCode();
    }

    /**
     * Run a command that READS from the terminal — the auth code pasted into
     * `gcloud auth login`, the keys `aws configure` asks for.
     *
     * runStreaming() cannot do these: its output callback forwards what the
     * child writes but never hands over stdin, so an input prompt waits
     * forever for something that never arrives. tty() gives the child our
     * actual terminal.
     *
     * Symfony throws rather than degrading when there is no TTY to hand over
     * (Windows, a pipe, CI), so fall back to streaming there and let the
     * command fail on its own terms instead of on ours.
     *
     * JSON mode falls back too: stdout is reserved for the result, and tty()
     * hands the child the terminal directly, so its output would land in the
     * middle of the JSON. A caller asking for machine-readable output is not
     * one a human is sitting at to answer a prompt anyway.
     */
    protected function runInteractive(string $command): int
    {
        if (State::isJsonMode() || ! SymfonyProcess::isTtySupported()) {
            return $this->runStreaming($command);
        }

        return Process::forever()->tty()->run($command)->exitCode();
    }
}
