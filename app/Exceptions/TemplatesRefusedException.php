<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** A downloaded app's templates were changed on disk, so they were not used. See AmbiguousEnvironmentException for the console marker. */
class TemplatesRefusedException extends RuntimeException implements ConsoleExceptionInterface
{
    public function __construct(public readonly string $app)
    {
        parent::__construct("The downloaded {$app} templates were changed on this machine, or their signature no longer checks out, so they were not used.");
    }

    public function renderForConsole(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln("  <fg=red;options=bold>The downloaded {$this->app} templates were not used</>");
        $output->writeln('  <fg=gray>They were changed on this machine, or their signature no longer checks out.</>');
        $output->writeln('');
        $output->writeln("  Keep your edits:     <fg=yellow>larakube templates:trust {$this->app}</>");
        $output->writeln("  Restore the release: <fg=yellow>larakube templates:update {$this->app} --force</>");
        $output->writeln('');
    }
}
