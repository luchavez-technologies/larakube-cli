<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasClusterSecretDbKey;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasSmtpWiring;
use App\Contracts\UsesForwardAuth;

/** The single vendor backing the RECORD category — 'Screen Recording & Sharing'. Only Sendrec. */
final class RecordTool implements ClusterToolVendor, HasClusterSecretDbKey, HasCommonsBuckets, HasCommonsDatabases, HasDbSecretRef, HasDeploymentBaseName, HasOidcWiring, HasSmtpWiring, UsesForwardAuth
{
    public function getLabel(): string
    {
        return 'Sendrec';
    }

    public function baseDeploymentName(): string
    {
        return 'record-sendrec';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'record-sendrec',
            'secret' => 'record-sendrec-smtp',
            // SendRec defaults to STARTTLS, which deadlocks on Stalwart's
            // 465 (implicit TLS) listener: plaintext EHLO vs a waiting TLS
            // handshake, 30s read timeout. Stalwart exposes no 587 listener,
            // so the CLIENT must do implicit TLS.
            //
            // The value for that is `tls`, NOT `implicit`. SendRec coerces
            // any unrecognised value back to starttls with only a startup
            // warning — `implicit` shipped here and silently reinstated the
            // very deadlock it was meant to fix. Confirmed from the pod:
            //   WARN "unrecognized SMTP_TLS value; falling back to starttls" value=implicit
            // Accepted values are starttls | tls | auto | none.
            'static' => [
                'SMTP_TLS' => 'tls',
            ],
            'vars' => [
                'host' => 'SMTP_HOST',
                'port' => 'SMTP_PORT',
                // NOT SMTP_USER / SMTP_PASS / SMTP_FROM — those matched only
                // as substrings; the real names are these.
                'user' => 'SMTP_USERNAME',
                'password' => 'SMTP_PASSWORD',
                'from' => 'EMAIL_FROM_ADDRESS',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        // SendRec has NO generic OIDC provider of its own — it hardcodes
        // Google/Microsoft/GitHub (GOOGLE_CLIENT_ID, MICROSOFT_CLIENT_ID,
        // GITHUB_SSO_CLIENT_ID; callback /api/auth/sso/{provider}/callback),
        // so Zitadel can never be an in-app login here. That is why it is a
        // FORWARDAUTH tool (ADR 0006): sso:wire gates it at Traefik via the
        // shared SSO proxy and deliberately sets nothing on the pod. The
        // redirect_path below is the PROXY's callback on auth.<domain>, not
        // a SendRec route — do not add 'static'/'vars' to match SendRec, and
        // do not expect an SSO button on its login screen: the gate
        // authorises access, the app still keeps its own accounts.
        return [
            'deployment' => 'record-sendrec',
            'secret' => 'record-sendrec-oidc',
            'redirect_path' => '/oauth2/callback',
        ];
    }

    public function dbSecretRef(): ?array
    {
        return ['secret' => 'record-sendrec-secrets', 'key' => 'db-password'];
    }

    public function commonsDatabaseList(): array
    {
        return ['record_sendrec'];
    }

    public function commonsBucketList(): array
    {
        return ['record-storage'];
    }

    public function clusterSecretDbKey(string $tenant): string
    {
        return 'RECORD_DB_PASSWORD';
    }
}
