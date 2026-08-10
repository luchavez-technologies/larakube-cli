<?php

namespace App\Contracts;

/**
 * A short operator-facing warning when this vendor's SSO integration is
 * real (its oidcEnv() vars are genuinely read by the app) but gated behind
 * a paid license even for self-hosted use. sso:wire still runs and
 * prepares the wiring, but login will not work until the license exists —
 * the CLI needs to say so loudly rather than let a successful "wired"
 * message imply a working login.
 */
interface HasSsoLicenseCaveat
{
    public function ssoLicenseCaveat(): ?string;
}
