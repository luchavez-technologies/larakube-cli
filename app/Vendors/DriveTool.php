<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the DRIVE category — 'Cloud Storage & Sync'. Only oCIS. */
final class DriveTool implements ClusterToolVendor, HasCommonsBuckets, HasOidcWiring, HasSmtpWiring, HasVpnWiring, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'oCIS';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '') ? 'drive-vpn-only' : "drive-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'app', role: ClusterToolComponentRole::PRIMARY, deployment: $name('drive-ocis'),
                container: 'ocis', backupVolume: true, backupPaths: ['/var/lib/ocis'],
            ),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        // oCIS is the canonical drive engine, so mail:wire targets it. Its
        // notifications service reads NOTIFICATIONS_SMTP_*; ssltls is
        // implicit TLS for Stalwart's port 465 (starttls|ssltls|none).
        return [
            'deployment' => 'drive-ocis',
            'secret' => 'drive-ocis-smtp',
            'static' => [
                'NOTIFICATIONS_SMTP_ENCRYPTION' => 'ssltls',
                'NOTIFICATIONS_SMTP_AUTHENTICATION' => 'login',
            ],
            'vars' => [
                'host' => 'NOTIFICATIONS_SMTP_HOST',
                'port' => 'NOTIFICATIONS_SMTP_PORT',
                'user' => 'NOTIFICATIONS_SMTP_USERNAME',
                'password' => 'NOTIFICATIONS_SMTP_PASSWORD',
                'from' => 'NOTIFICATIONS_SMTP_SENDER',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'drive-ocis',
            'secret' => 'drive-ocis-oidc',
            // oCIS web is a browser SPA doing the full authorize+token
            // exchange in-page with PKCE — the served config.json's
            // openIdConnect block carries NO client_secret (verified live
            // 2026-07-31). Registering it as a confidential client like
            // Grafana/Vaultwarden makes Zitadel demand client auth at the
            // token endpoint the browser can't provide (invalid_client on
            // every login); a public client is what oCIS's own web client
            // assumes. sso:wire must then not push a client secret either.
            'public_client' => true,
            'static' => [
                'PROXY_AUTOPROVISION_ACCOUNTS' => 'true',
                // Resolve SSO users by their email claim. oCIS looks the
                // value up against the attribute named by PROXY_USER_CS3_CLAIM
                // (default "username"), so leaving that at its default would
                // query username == <email> and never match an autoprovisioned
                // account (which is minted with preferred_username as its
                // username). "mail" makes resolution self-consistent.
                'PROXY_USER_OIDC_CLAIM' => 'email',
                'PROXY_USER_CS3_CLAIM' => 'mail',
                // OIDC role assignment. This used to be "default": the oidc
                // driver locks a user out if their token carries no role claim
                // matching the built-in mapping (ocisAdmin/ocisSpaceAdmin/
                // ocisUser/ocisGuest), and Zitadel's native roles claim is a
                // nested object, never a flat list, so the Keycloak-style oidc
                // example can't be copied verbatim. That gap is closed on the
                // Zitadel side instead: sso:wire installs an org-wide Action
                // ("flattenOcisRoles") that ALWAYS emits a flat top-level
                // `ocisRoles` claim — ["ocisAdmin"] / ["ocisSpaceAdmin"] when
                // the user holds the drive ocisAdmin/ocisSpaceAdmin role on
                // the shared project (admin outranks spaceadmin), otherwise
                // ["ocisUser"]. That no-match guarantee is what makes
                // driver=oidc safe here: oCIS re-asserts the role from the
                // claim on EVERY login (dynamic promote/demote — a manual
                // admin-settings role edit would be overwritten), and a user
                // with zero grants still lands on ocisUser instead of being
                // denied. The claim maps through oCIS's built-in default
                // mapping (ocisAdmin->admin, ocisSpaceAdmin->spaceadmin,
                // ocisUser->user), so no role-mapping yaml is needed.
                'PROXY_ROLE_ASSIGNMENT_DRIVER' => 'oidc',
                'PROXY_ROLE_ASSIGNMENT_OIDC_CLAIM' => 'ocisRoles',
                // Desktop/iOS/Android clients discover the OIDC provider at
                // drive.<host>/.well-known/openid-configuration; without this
                // rewrite they'd hit oCIS's builtin discovery instead of
                // Zitadel's. Matches the canonical Keycloak external-IDP
                // deployment example.
                'PROXY_OIDC_REWRITE_WELLKNOWN' => 'true',
                // Zitadel issues opaque (non-JWT) access tokens by default,
                // so oCIS's default jwt verify rejects every API call with
                // "token contains an invalid number of segments" -> 401 ->
                // oCIS web's "Not logged in" error page (verified live
                // 2026-08-01 in the proxy log after a successful Zitadel
                // login). oCIS 8.0.6's PROXY_OIDC_ACCESS_TOKEN_VERIFY_METHOD
                // accepts only "none" or "jwt"; "none" validates the access
                // token against the IdP's userinfo endpoint server-side
                // (Zitadel /oidc/v1/userinfo), which is the supported method
                // for opaque tokens. PROXY_OIDC_SKIP_USER_INFO must stay
                // unset: it is incompatible with "none".
                'PROXY_OIDC_ACCESS_TOKEN_VERIFY_METHOD' => 'none',
            ],
            'vars' => [
                'client_id' => 'WEB_OIDC_CLIENT_ID',
                'client_secret' => 'OCIS_OIDC_CLIENT_SECRET',
                'issuer' => 'OCIS_OIDC_ISSUER',
            ],
            // oCIS web's real OIDC callback page, not the tool root. The
            // root used to be registered here, which made Zitadel 400 the
            // authorize request with "redirect_uri not allowed" (verified
            // live: /oauth/v2/authorize 400s for /oidc-callback.html but
            // 302s for /). OwnCloud web also renews tokens via its own
            // silent-redirect page — see oidcRedirectUris().
            'redirect_path' => '/oidc-callback.html',
        ];
    }

    public function commonsBucketList(): array
    {
        return ['drive-ocis'];
    }
}
