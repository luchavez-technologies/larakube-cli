<?php

namespace App\Traits\CliTools;

/**
 * Self-heals the one recoverable `brew install` failure seen live: parts of
 * the Homebrew prefix owned by the wrong user. Needs StreamsProcessOutput.
 */
trait RepairsHomebrewPermissions
{
    /**
     * Homebrew's own prefix has occasionally ended up owned by the wrong
     * user — confirmed live (2026-10-24): a `brew install --cask gcloud-cli`
     * failing outright with "/opt/homebrew is not writable", even though
     * Homebrew itself is present and otherwise working.
     *
     * Originally this extracted and re-ran Homebrew's own suggested
     * `sudo chown -R <user> <dirs...>` line verbatim — but a second live
     * failure the same day proved that assumption wrong: a single
     * `brew install` can print an early one-line "<path> is not writable...
     * sudo chown -R <user> <path>" warning, then later — once dependency
     * resolution reaches directories that warning never covered
     * (`share/doc`, `share/zsh/site-functions`, …) — a second, FATAL
     * "The following directories are not writable by your user:" block that
     * lists several more paths with no suggested command at all. The old
     * regex only ever matched the first block, so the retry chowned too
     * little and failed the exact same way. This now collects every
     * absolute path Homebrew mentioned as unwritable, from either format,
     * and chowns all of them in one pass with our own `$(whoami)` rather
     * than depending on Homebrew's own wording for the command itself.
     *
     * Runs with `sudo -n` (never interactive): if passwordless sudo isn't
     * already available — e.g. a local `larakube setup` grants it for the
     * length of the run, same mechanism InstallTool runs use via
     * CliRunner::needsElevation() — this fails immediately rather than
     * hanging on a password prompt nothing can answer when driven from
     * Desktop (no terminal). A human running the CLI directly in a real
     * terminal still sees Homebrew's own suggested fix in the streamed
     * output either way and can run it by hand.
     *
     * @return bool whether at least one unwritable path was found AND the chown exited 0
     */
    protected function attemptHomebrewPermissionFix(string $failedOutput): bool
    {
        $paths = $this->homebrewUnwritablePaths($failedOutput);

        if ($paths === []) {
            return false;
        }

        $code = $this->runStreaming('sudo -n chown -R $(whoami) '.implode(' ', array_map('escapeshellarg', $paths)));

        return $code === 0;
    }

    /**
     * Every absolute path Homebrew flagged as not writable, across however
     * many of its two known message shapes a single run printed:
     * "<path> is not writable...", its own suggested "sudo chown -R <user>
     * <path...>" line, and a bare "The following directories are not
     * writable by your user:\n<path>\n<path>\n..." block (confirmed live
     * 2026-10-24 to appear with NO suggested command of its own).
     *
     * @return list<string>
     */
    protected function homebrewUnwritablePaths(string $output): array
    {
        $paths = [];

        // Not line-anchored: Homebrew's own phrasing is "Error: <path> is not
        // writable.", so the path never starts the line.
        if (preg_match_all('#(/\S+) is not writable\.#', $output, $matches) > 0) {
            array_push($paths, ...$matches[1]);
        }

        if (preg_match_all('/^\s*sudo chown -R \S+((?: \S+)+)\s*$/m', $output, $matches) > 0) {
            foreach ($matches[1] as $group) {
                array_push($paths, ...array_filter(explode(' ', trim($group))));
            }
        }

        if (preg_match_all('/not writable by your user:\n((?:\/\S+\n?)+)/', $output, $matches) > 0) {
            foreach ($matches[1] as $block) {
                array_push($paths, ...array_filter(explode("\n", trim($block))));
            }
        }

        return array_values(array_unique($paths));
    }
}
