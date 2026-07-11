<?php

namespace App;

class State
{
    public static bool $headerRendered = false;

    /**
     * Sensitive values registered for redaction in CLI output (keyed by value).
     * Lives here, not on the LaraKubeOutput trait, because that trait is also
     * mixed into the driver enums — and PHP enums may not declare properties.
     *
     * @var array<string, true>
     */
    public static array $registeredSecrets = [];

    /**
     * Machine-readable output mode (cloud:create --json): stdout is reserved
     * for a single JSON result, all human-readable output goes to stderr.
     */
    public static bool $jsonMode = false;

    /**
     * A DO token supplied for this run only (--do-token / TF_VAR_do_token) —
     * consulted by getDoToken() ahead of the global config, never persisted.
     */
    public static ?string $transientDoToken = null;

    /**
     * The last laraKubeError() message (already secret-masked), so a JSON-mode
     * wrapper can report the failure without threading it through every
     * `return 1` site.
     */
    public static ?string $lastError = null;

    /**
     * The command's original stdout, captured before enableJsonMode() reroutes
     * $this->output to stderr — jsonOutput() writes the final result here.
     */
    public static ?\Illuminate\Console\OutputStyle $stdout = null;
}
