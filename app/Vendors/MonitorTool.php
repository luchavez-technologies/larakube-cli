<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasPresenceProbe;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasToolAccessDetails;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWhiteLabel;
use Illuminate\Support\Facades\Process;

/** The single vendor backing the MONITOR category — 'Monitoring Stack'. Only Grafana. */
final class MonitorTool implements ClusterToolVendor, HasDeploymentBaseName, HasOidcWiring, HasPresenceProbe, HasSmtpWiring, HasToolAccessDetails, HasVpnWiring, HasWhiteLabel
{
    public function getLabel(): string
    {
        return 'Grafana';
    }

    public function baseDeploymentName(): string
    {
        return 'grafana';
    }

    /** No 'port' key in vars — a real, pre-existing quirk, not an omission. */
    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'grafana',
            'secret' => 'grafana-smtp',
            'static' => [
                'GF_SMTP_ENABLED' => 'true',
            ],
            'vars' => [
                'host' => 'GF_SMTP_HOST',
                'user' => 'GF_SMTP_USER',
                'password' => 'GF_SMTP_PASSWORD',
                'from' => 'GF_SMTP_FROM_ADDRESS',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'grafana',
            'secret' => 'grafana-oidc',
            'static' => [
                'GF_AUTH_GENERIC_OAUTH_ENABLED' => 'true',
                'GF_AUTH_GENERIC_OAUTH_NAME' => 'Login with SSO',
                'GF_AUTH_GENERIC_OAUTH_SCOPES' => 'openid profile email',
                'GF_AUTH_GENERIC_OAUTH_USE_PKCE' => 'true',
                // Gate login itself, not just the assigned role — least-
                // privilege default (per audit: Grafana has no non-admin
                // "gate at the door" of its own, unlike OpenBao's
                // bound_claims). larakube_roles is the flattened claim
                // ensureRbacGating()/zitadelEnsureRbacAction() maintain;
                // Zitadel's native roles claim is a nested object
                // Grafana's role_attribute_path (JMESPath) can't read.
                // Priority order matters — first true branch wins, so
                // admin is checked before editor before user. The ''
                // fallback + STRICT deny-on-no-match was verified live
                // 2026-07-30 (real login, no role → "IdP did not return
                // a role attribute", not a silent Viewer fallback).
                // 'Admin' here is Grafana's ORG admin (can manage this
                // org's users/datasources/plugins), not the separate
                // server-wide GrafanaAdmin superadmin flag — that one's
                // gated by ALLOW_ASSIGN_GRAFANA_ADMIN below, which stays
                // false: nothing here should ever request it, since a
                // single-org deployment has no cross-org admin need.
                'GF_AUTH_GENERIC_OAUTH_ROLE_ATTRIBUTE_PATH' => "contains(larakube_roles[*], 'grafana-admin') && 'Admin' || contains(larakube_roles[*], 'grafana-editor') && 'Editor' || contains(larakube_roles[*], 'grafana-user') && 'Viewer' || ''",
                'GF_AUTH_GENERIC_OAUTH_ROLE_ATTRIBUTE_STRICT' => 'true',
                'GF_AUTH_GENERIC_OAUTH_ALLOW_ASSIGN_GRAFANA_ADMIN' => 'false',
            ],
            'sso_only_vars' => [
                'GF_AUTH_DISABLE_LOGIN_FORM' => 'true',
                'GF_USERS_ALLOW_SIGN_UP' => 'false',
            ],
            'vars' => [
                'client_id' => 'GF_AUTH_GENERIC_OAUTH_CLIENT_ID',
                'client_secret' => 'GF_AUTH_GENERIC_OAUTH_CLIENT_SECRET',
                'auth_url' => 'GF_AUTH_GENERIC_OAUTH_AUTH_URL',
                'token_url' => 'GF_AUTH_GENERIC_OAUTH_TOKEN_URL',
                'userinfo_url' => 'GF_AUTH_GENERIC_OAUTH_API_URL',
            ],
            // Grafana derives its own callback from GF_SERVER_ROOT_URL — this
            // is the fixed suffix sso:wire appends to the tool's own host when
            // registering the redirect URI with Zitadel.
            'redirect_path' => '/login/generic_oauth',
        ];
    }

    public function whiteLabel(): array
    {
        return ['app_name_key' => 'GF_BRANDING_APP_TITLE', 'logo_url_key' => 'GF_BRANDING_FAV_ICON'];
    }

    public function toolAccessRows(?string $host, string $env, string $kubectl, string $instance = 'main'): array
    {
        $ns = ($instance === 'main' || $instance === null || $instance === '') ? 'larakube-monitoring' : "larakube-monitoring-{$instance}";
        $passVal = trim(Process::run(
            "{$kubectl} get secret grafana-admin -n {$ns} -o jsonpath='{.data.password}' --ignore-not-found",
        )->output());
        $decodedPass = $passVal !== '' ? (base64_decode($passVal, true) ?: '<unknown>') : '<unknown>';

        return [
            ['Grafana Login', "admin / {$decodedPass}"],
            ['Prometheus', "http://prometheus.{$ns}.svc.cluster.local:9090 (in-cluster)"],
            ['Loki', "http://loki.{$ns}.svc.cluster.local:3100 (in-cluster)"],
        ];
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $deployment = ($instance === null || $instance === '' || $instance === 'main') ? 'grafana' : "grafana-{$instance}";

        return [
            'deployment' => $deployment,
            'secret' => 'grafana-admin',
            'middlewareName' => 'larakube-vpn-mesh',
            'namespace' => 'larakube-monitoring',
        ];
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        $deployment = ($instance === null || $instance === '' || $instance === 'main') ? 'grafana' : "grafana-{$instance}";

        return "deployment/{$deployment} -n larakube-monitoring";
    }
}
