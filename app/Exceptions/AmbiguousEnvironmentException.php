<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Thrown when a command was given a real cluster domain but no environment, so
 * "which cluster does this deploy to" has no safe answer.
 *
 * The old behaviour was to quietly assume `local`, which meant
 * `secrets:init --domain=example.com` wired a production hostname into a
 * local-TLS ingress and applied it to whatever kube-context happened to be
 * current. Failing loudly is the only correct move: a domain is evidence the
 * operator meant a cloud environment, not evidence of which one.
 */
/*
 * Implements Symfony's console ExceptionInterface deliberately: Collision only
 * hands an exception back to the application's handler when it carries that
 * marker — everything else it renders itself as a stack trace. Without it, the
 * actionable message below is never reached.
 */
class AmbiguousEnvironmentException extends RuntimeException implements ConsoleExceptionInterface
{
    /** @param  list<string>  $known */
    public function __construct(
        public readonly string $command,
        public readonly string $domain,
        public readonly array $known = [],
    ) {
        parent::__construct("--domain={$domain} was given without an environment.");
    }

    public function renderForConsole(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('  <fg=red;options=bold>Which environment?</>');
        $output->writeln("  <fg=gray>You passed --domain={$this->domain} but no environment.</>");
        $output->writeln('');
        $output->writeln('  A domain does not say which cluster to deploy to. Naming it explicitly');
        $output->writeln('  avoids wiring a real hostname into a local-TLS ingress on the wrong cluster.');
        $output->writeln('');

        $example = $this->known !== [] ? $this->known[0] : 'production';
        $output->writeln("  <fg=gray>e.g.</> <fg=yellow>larakube {$this->command} {$example} --domain={$this->domain}</>");

        if ($this->known !== []) {
            $output->writeln('  <fg=gray>known environments: </>'.implode(', ', $this->known));
        }

        $output->writeln('');
    }
}
