<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Thrown when a command needs a value that would normally come from a prompt,
 * but the command is running non-interactively (`--no-interaction`, or stdin is
 * not a TTY — CI, `larakube` proxy calls, MCP tool invocations).
 *
 * The whole point of the `{tool}:{action} {environment} --flag=value` revamp is
 * that every command is CLI-driveable, so "we can't prompt" must be a loud,
 * actionable failure naming the exact flag to pass — never a silent default
 * that targets the wrong cluster.
 */
/* See AmbiguousEnvironmentException for why the Symfony console marker matters. */
class MissingFlagException extends RuntimeException implements ConsoleExceptionInterface
{
    public function __construct(
        public readonly string $flag,
        public readonly string $purpose,
        public readonly ?string $example = null,
    ) {
        parent::__construct("Missing required --{$flag} ({$purpose}) in non-interactive mode.");
    }

    /**
     * Laravel's console exception handler calls this when present, so the
     * operator gets the flag name and a copy-pasteable example instead of a
     * stack trace.
     */
    public function renderForConsole(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln("  <fg=red;options=bold>Missing --{$this->flag}</>");
        $output->writeln("  <fg=gray>{$this->purpose}</>");
        $output->writeln('');
        $output->writeln('  This command is running non-interactively, so it cannot prompt.');
        $output->writeln("  Pass <fg=yellow>--{$this->flag}=…</> explicitly.");

        if ($this->example !== null) {
            $output->writeln('');
            $output->writeln("  <fg=gray>e.g.</> <fg=yellow>{$this->example}</>");
        }

        $output->writeln('');
    }
}
