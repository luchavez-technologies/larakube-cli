<?php

namespace App\Traits;

/**
 * Collects critical one-time follow-up notes (e.g. "the Windows hosts file
 * wasn't synced — add this line manually") during a long-running command, and
 * re-prints them all at the very end. A warning printed once near the top of
 * a 100+ line run (deploy steps, kustomize apply, restarts, ...) is easy to
 * miss and easy to forget by the time the command finishes.
 *
 * Declares an instance property — Command classes only, never mix this into
 * an Enum (PHP forbids non-static properties there).
 */
trait CollectsReminders
{
    /** @var string[] */
    protected array $reminders = [];

    /**
     * Re-print every reminder collected during this run as the very last thing
     * on screen. No-op when nothing was collected.
     */
    protected function renderReminders(): void
    {
        if ($this->reminders === []) {
            return;
        }

        $this->newLine();
        $this->laraKubeWarn('Before you continue — one-time action(s) needed:');
        foreach ($this->reminders as $reminder) {
            $this->line("  {$reminder}");
        }
    }
}
