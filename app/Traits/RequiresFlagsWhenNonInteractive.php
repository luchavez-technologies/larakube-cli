<?php

namespace App\Traits;

use App\Exceptions\MissingFlagException;

/**
 * Makes every prompt in a command optional-by-flag, so the command can be
 * driven entirely from the CLI (CI, the `larakube` proxy, MCP) while still
 * being pleasant to use by hand.
 *
 * The contract for every value a command needs:
 *
 *   1. `--flag=value` was passed        → use it, never prompt.
 *   2. Interactive TTY, no flag         → prompt (unchanged human UX).
 *   3. Non-interactive, no flag         → throw MissingFlagException, which
 *                                         names the flag and shows an example.
 *
 * Case 3 is deliberately a hard failure rather than a default. An earlier
 * design defaulted the environment to `local` when it couldn't prompt, which
 * meant a mistyped CI invocation silently deployed to the wrong cluster.
 */
trait RequiresFlagsWhenNonInteractive
{
    /**
     * True when we must not prompt: an explicit --no-interaction, or stdin is
     * not a terminal (piped input, CI runner, MCP tool call). Both matter —
     * Laravel Prompts will happily hang forever on a non-TTY stdin otherwise.
     */
    protected function cannotPrompt(): bool
    {
        if ($this->option('no-interaction') || app()->runningUnitTests()) {
            return true;
        }

        return ! stream_isatty(STDIN);
    }

    /**
     * Resolve a value from `--{$flag}`, falling back to $prompt on a TTY and
     * failing loudly otherwise.
     *
     * @param  callable(): (string|null)  $prompt  Only invoked on an interactive TTY.
     */
    protected function flagOrPrompt(
        string $flag,
        callable $prompt,
        string $purpose,
        ?string $example = null,
    ): string {
        $value = $this->option($flag);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException($flag, $purpose, $example);
        }

        return (string) $prompt();
    }

    /**
     * Boolean sibling of flagOrPrompt() for confirm()-style questions. Unlike
     * the string case, a missing boolean flag is NOT fatal — the flag's absence
     * is itself a valid answer. Non-interactively we take $default, which every
     * caller sets to the safe/no-op choice (don't wire, don't restart), so an
     * unattended run never performs an action nobody asked for.
     *
     * @param  callable(): bool  $prompt  Only invoked on an interactive TTY.
     */
    protected function flagOrConfirm(string $flag, callable $prompt, bool $default = false): bool
    {
        if ($this->option($flag)) {
            return true;
        }

        // An explicit --no-{flag} always wins, interactive or not.
        if ($this->hasOption("no-{$flag}") && $this->option("no-{$flag}")) {
            return false;
        }

        if ($this->cannotPrompt()) {
            return $default;
        }

        return $prompt();
    }

    /**
     * Validate a flag's value against a fixed set (typically an enum's keys),
     * so a typo fails immediately with the valid choices listed rather than
     * surfacing later as a confusing kubectl error.
     *
     * @param  array<string, string>  $allowed  slug => label
     */
    protected function assertAllowed(string $flag, string $value, array $allowed): string
    {
        if (isset($allowed[$value])) {
            return $value;
        }

        throw new MissingFlagException(
            $flag,
            "'{$value}' is not valid. Choose one of: ".implode(', ', array_keys($allowed)),
            '--'.$flag.'='.array_key_first($allowed),
        );
    }
}
