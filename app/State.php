<?php

namespace App;

use Illuminate\Console\OutputStyle;

class State
{
    public static bool $headerRendered = false;

    public static bool $isTesting = false;

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
     * A Cloudflare API token supplied for this run only, never persisted.
     */
    public static ?string $transientCloudflareToken = null;

    /**
     * A GCP account email supplied for this run only (--gcp-account), never persisted.
     */
    public static ?string $transientGcpAccount = null;

    /**
     * A GCP project ID supplied for this run only (--gcp-project), never persisted.
     */
    public static ?string $transientGcpProject = null;

    /**
     * A GCP credentials path/token supplied for this run only (--gcp-credentials), never persisted.
     */
    public static ?string $transientGcpCredentials = null;

    /**
     * An AWS profile supplied for this run only (--aws-profile), never persisted.
     */
    public static ?string $transientAwsProfile = null;

    /**
     * An AWS region supplied for this run only (--aws-region), never persisted.
     */
    public static ?string $transientAwsRegion = null;

    /**
     * An AWS Access Key ID supplied for this run only (--aws-access-key-id), never persisted.
     */
    public static ?string $transientAwsAccessKeyId = null;

    /**
     * An AWS Secret Access Key supplied for this run only (--aws-secret-access-key), never persisted.
     */
    public static ?string $transientAwsSecretAccessKey = null;

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
    public static ?OutputStyle $stdout = null;
}
