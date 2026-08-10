<?php

namespace App\Traits;

use Symfony\Component\Console\Input\InputInterface;

/**
 * option() tolerant of an unbound console input. Trait methods shared across
 * commands (and harness tests that instantiate a command directly or compose
 * traits onto a plain class, e.g. CloudCreateNamingTest /
 * CloudConfigureConsolidationTest) can't assume $this->input exists or that
 * the command's definition declares the option — flag() returns null in all
 * of those cases instead of fataling, so a prompt fallback still runs.
 */
trait ReadsCommandOptions
{
    protected function flag(string $key): mixed
    {
        $input = property_exists($this, 'input') ? $this->input : null;

        if (! $input instanceof InputInterface) {
            return null;
        }

        return $input->hasOption($key) ? $input->getOption($key) : null;
    }
}
