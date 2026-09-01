<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasPresenceProbe;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasToolAccessDetails;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWhiteLabel;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;
use Illuminate\Support\Facades\Process;

/** The single vendor backing the MONITOR category — Grafana, Prometheus and Loki. */
final class MonitorTool implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasOidcWiring, HasOpenbaoSync, HasPresenceProbe, HasRotatableDatabasePassword, HasSmtpWiring, HasToolAccessDetails, HasVpnWiring, HasWhiteLabel, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Grafana';
    }

    public function baseDeploymentName(): string
    {
        return 'monitor-grafana';
    }

    /**
     * Grafana's own database (dashboards created/edited via the UI, folders,
     * alert rules, users) — previously unset, so it defaulted to Grafana's
     * built-in SQLite file on the pod's ephemeral filesystem. Nothing backed
     * it with a PVC, so it was wiped on every pod recreation (a rollout
     * restart, a node reboot, anything). Confirmed live 2026-08-18 — a
     * teammate's dashboard work was lost this way. Dashboards-as-code (the
     * JSON files monitor:init provisions into the 'LaraKube' folder) were
     * never affected — those are re-read from a ConfigMap on every boot.
     */
    public function commonsDatabaseList(): array
    {
        return ['grafana'];
    }

    public function dbSecretRef(): ?array
    {
        return ['secret' => 'monitor-secrets', 'key' => 'db-password'];
    }

    public function openbaoSyncConfig(?string $instance = null): array
    {
        return [
            'secret' => 'monitor-secrets',
            'keys' => ['GRAFANA_DB_PASSWORD'],
        ];
    }

    /** No 'port' key in vars — a real, pre-existing quirk, not an omission. */
    public function smtpEnv(?string $instance = null): ?array
    {
        $instanceName = ($instance !== null && $instance !== '') ? $instance : 'monitor';

        return [
            'deployment' => "monitor-grafana-{$instanceName}",
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
        $instanceName = ($instance !== null && $instance !== '') ? $instance : 'monitor';

        return [
            'deployment' => "monitor-grafana-{$instanceName}",
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

    public function toolAccessRows(?string $host, string $env, string $kubectl, ?string $instance = null): array
    {
        $ns = ($instance === null || $instance === '') ? 'larakube-shared' : "larakube-shared-{$instance}";
        $passVal = trim(Process::run(
            "{$kubectl} get secret monitor-secrets -n {$ns} -o jsonpath='{.data.password}' --ignore-not-found",
        )->output());
        $decodedPass = $passVal !== '' ? (base64_decode($passVal, true) ?: '<unknown>') : '<unknown>';

        $instanceName = ($instance !== null && $instance !== '') ? $instance : 'monitor';
        $lokiName = "monitor-loki-{$instanceName}";
        $promName = "monitor-prometheus-{$instanceName}";

        return [
            ['Grafana Login', "admin / {$decodedPass}"],
            ['Prometheus', "http://{$promName}.{$ns}.svc.cluster.local:9090 (in-cluster)"],
            ['Loki', "http://{$lokiName}.{$ns}.svc.cluster.local:3100 (in-cluster)"],
        ];
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $instanceName = ($instance !== null && $instance !== '') ? $instance : 'monitor';
        $name = "grafana-vpn-only-{$instanceName}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        $instanceName = ($instance !== null && $instance !== '') ? $instance : 'monitor';

        return "deployment/monitor-grafana-{$instanceName} -n larakube-shared";
    }

    /**
     * Loki and Prometheus are Monitor's, not free-floating infrastructure —
     * MonitorInitCommand has always built these exact names, but they were
     * never declared here, so forDeployment() could not map them and
     * MonitorRemoveCommand had to hand-copy the teardown strings that this
     * contract exists to replace.
     *
     * backupVolume stays false on both: metrics and logs are regenerable
     * telemetry, and silently enlarging every nightly backup is not a
     * side effect a naming fix should have.
     *
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        // Null-safe like every other vendor: forDeployment()'s reverse lookup
        // calls this with no instance precisely because it is trying to
        // discover one.
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'grafana',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('monitor-grafana'),
            ),
            new ClusterToolComponentData(
                key: 'prometheus',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('monitor-prometheus'),
            ),
            new ClusterToolComponentData(
                key: 'loki',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('monitor-loki'),
            ),
        ];
    }
}
