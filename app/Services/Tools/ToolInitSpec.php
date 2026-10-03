<?php

namespace App\Services\Tools;

use App\Enums\ClusterTool;

/**
 * The options each Cluster Tool's init command takes, in one place. This is the
 * only description of them: the init commands build their signatures from it
 * and `tool:list --json` hands it to Desktop as form fields. A tool added from
 * now on is described here and nowhere else.
 */
final class ToolInitSpec
{
    /**
     * @return list<InitOption>
     */
    public static function for(ClusterTool $tool, ?string $engine = null): array
    {
        return match ($tool->canonicalTool($engine)) {
            ClusterTool::BULWARK => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Bulwark webmail (example.com → prefix.example.com)'),
                InitOption::value('app-name', 'Branding shown on the webmail login/app (default: "Webmail")'),
                InitOption::vpnOnly(),
                InitOption::flag('no-mail-restart', 'Skip the brief Stalwart restart that applies the CORS change'),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::CHATWOOT => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Chatwoot (example.com → prefix.example.com)'),
                InitOption::value('app-name', 'Custom branding name for Chatwoot (defaults to Support)'),
                InitOption::value('logo-url', 'Custom logo URL for Chatwoot'),
                InitOption::value('admin-email', 'Primary admin email for Chatwoot'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::DIRECTUS => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Directus (example.com → prefix.example.com). Omit to target/update the default instance'),
                InitOption::list('alias', 'Additional domain alias(es) to register on this instance\'s Ingress'),
                InitOption::value('admin-email', 'Email for the primary admin account'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(defaultOn: true),
            ],
            ClusterTool::DOCUMENSO => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Documenso (example.com → prefix.example.com)'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::EXTERNAL_DNS => [
                InitOption::value('cloudflare-token', 'API token — every zone it can see is discovered and managed, unless --zone= narrows that. Or set LARAKUBE_CLOUDFLARE_TOKEN'),
                InitOption::list('zone', 'Optional — restrict to a subset of what the token can see. Omit to manage every zone the token has access to.'),
                InitOption::value('group', 'Stable name for this instance. Default: the sole zone\'s own slug (unchanged single-zone behavior) — required when 2+ zones are in scope'),
                InitOption::context(),
                InitOption::force(),
            ],
            ClusterTool::FORGEJO => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Forgejo (example.com → git.example.com; git.example.com used as-is)'),
                InitOption::value('app-name', 'Custom branding name for Forgejo (defaults to Forgejo)'),
                InitOption::value('logo-url', 'Custom logo URL for Forgejo'),
                InitOption::value('admin-email', 'Email for the Forgejo admin account (defaults to admin@<your domain>)'),
                InitOption::flag('no-plex', 'Bypass Plex Commons and use local PVC storage instead'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::GLITCHTIP => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for GlitchTip (example.com → errors.example.com; errors.example.com used as-is)'),
                InitOption::value('app-name', 'Custom branding name for GlitchTip (defaults to Error Tracking)'),
                InitOption::value('logo-url', 'Custom logo URL for GlitchTip'),
                InitOption::value('admin-email', 'Primary administrator email for GlitchTip'),
                InitOption::flag('no-plex', 'Bypass Plex Commons and deploy dedicated database/cache pods instead'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::GRAFANA => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Grafana (example.com → grafana.example.com; grafana.example.com used as-is)'),
                InitOption::value('app-name', 'Custom branding name for Grafana (defaults to Monitor)'),
                InitOption::value('logo-url', 'Custom logo / favicon URL for Grafana'),
                InitOption::vpnOnly(),
                InitOption::flag('no-logs', 'Skip deploying Loki + Promtail log aggregation (~300MB RAM saved)'),
                InitOption::flag('with-logs', 'Force deploying Loki + Promtail log aggregation'),
                InitOption::flag('no-traces', 'Skip deploying Tempo trace storage (~450MB RAM saved)'),
                InitOption::flag('with-traces', 'Force deploying Tempo trace storage'),
                InitOption::flag('no-plex', 'Bypass Plex Commons — Grafana keeps its own database on a local PVC instead of Commons Postgres'),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::HEADLAMP => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Headlamp (example.com → dashboard.example.com)'),
                InitOption::value('app-name', 'Custom branding name for Headlamp'),
                InitOption::value('logo-url', 'Custom logo URL for Headlamp'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::KUMA => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Uptime Kuma (example.com → status.example.com; status.example.com used as-is)'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::KUTT => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Kutt (example.com → prefix.example.com)'),
                InitOption::value('app-name', 'Custom branding name for Kutt (defaults to Links)'),
                InitOption::value('logo-url', 'Custom logo URL for Kutt'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(defaultOn: true),
            ],
            ClusterTool::LIVEKIT => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for LiveKit (example.com → meet.example.com)'),
                InitOption::vpnOnly(),
                InitOption::flag('no-host-port', 'Skip hostPort on LiveKit — use on managed K8s with a real LoadBalancer'),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::MATRIX => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Matrix (example.com → prefix.example.com)'),
                InitOption::value('app-name', 'Custom branding name for the Element Web UI (defaults to Matrix)'),
                InitOption::value('logo-url', 'Custom logo URL for the Element Web UI'),
                InitOption::flag('no-plex', 'Bypass Plex Commons and bundle dedicated storage'),
                InitOption::vpnOnly(),
                InitOption::flag('no-host-port', 'Skip hostPort on Coturn — use on managed K8s with a real LoadBalancer'),
                InitOption::value('media-retention', 'Keep media local for this long after last access; older files live only in S3 (s, h, d, m, y)', default: '30d'),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::METABASE => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Metabase (example.com → prefix.example.com)'),
                InitOption::value('app-name', 'Custom branding name for Metabase (defaults to Metabase)'),
                InitOption::value('logo-url', 'Custom logo URL for Metabase'),
                InitOption::value('admin-email', 'Primary administrator email for Metabase'),
                InitOption::flag('no-plex', 'Bypass Plex Commons and deploy a dedicated database'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::N8N => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for n8n (example.com → prefix.example.com)'),
                InitOption::flag('no-plex', 'Bypass Plex Commons and use local SQLite storage'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::NETBIRD => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for NetBird VPN (example.com → vpn.example.com; vpn.example.com used as-is)'),
                InitOption::value('sso-domain', 'Email domain every SSO login is grouped under (defaults to the base domain of --domain)'),
                InitOption::flag('no-plex', 'Keep NetBird on its own SQLite file instead of Commons Postgres'),
                InitOption::force(),
            ],
            ClusterTool::OCIS => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for oCIS (example.com → prefix.example.com)'),
                InitOption::flag('no-plex', 'Bypass Plex Commons (SQLite/Local PVC instead of Postgres/S3)'),
                InitOption::vpnOnly(),
                InitOption::value('extensions', 'Comma-separated web extensions to pre-install (e.g. "drawio,excalidraw")'),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::OPENBAO => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for OpenBao (example.com → secrets.example.com; secrets.example.com used as-is)'),
                InitOption::vpnOnly(),
                InitOption::force(),
            ],
            ClusterTool::OUTLINE => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Outline (example.com → prefix.example.com). Omit to target/update the default instance; pass a different host to deploy an ADDITIONAL instance there — the host you give IS its identity'),
                InitOption::list('alias', 'Additional domain alias(es) to register on this instance\'s Ingress'),
                InitOption::value('admin-email', 'Primary administrator email for Outline'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::PENPOT => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Penpot (example.com → prefix.example.com)'),
                InitOption::value('admin-email', 'Primary administrator email for Penpot'),
                InitOption::flag('with-exporter', 'Also deploy the Penpot Exporter (Playwright/Chromium) container for PDF/PNG exports'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::PLANKA => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Planka (example.com → prefix.example.com)'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::PLAUSIBLE => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Plausible (example.com → prefix.example.com)'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::POCKETBASE => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for PocketBase (example.com → prefix.example.com). Omit to target/update the default instance'),
                InitOption::list('alias', 'Additional domain alias(es) to register on this instance\'s Ingress'),
                InitOption::value('admin-email', 'Email for the primary admin account'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(defaultOn: true),
            ],
            ClusterTool::RESUME => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Resume (example.com → prefix.example.com)'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::SENDREC => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Sendrec (example.com → prefix.example.com)'),
                InitOption::vpnOnly(),
                InitOption::flag('allow-registration', 'Open public sign-up (needed once to create the first account, then re-run without it)'),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::STALWART => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Stalwart (example.com → prefix.example.com)'),
                InitOption::list('alias', 'Additional domain alias(es) to register on the Ingress'),
                InitOption::value('admin-email', 'Primary postmaster / admin email address for Stalwart'),
                InitOption::vpnOnly('Restrict the admin UI via NetBird VPN IP whitelisting'),
                InitOption::flag('host-port', 'Bind mail ports directly to the node (default on single-node k3s)'),
                InitOption::flag('no-host-port', 'Skip hostPort — use on managed K8s with a real LoadBalancer'),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::TEABLE => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Teable (example.com → prefix.example.com)'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::TWENTY => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Twenty (example.com → prefix.example.com)'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::UMAMI => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Umami (example.com → prefix.example.com)'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::VAULTWARDEN => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Vaultwarden (example.com → vault.example.com; vault.example.com used as-is)'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::WINDMILL => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Windmill (example.com → prefix.example.com)'),
                InitOption::flag('no-plex', 'Bypass Plex Commons and use local storage'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::YOPASS => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Yopass (example.com → paste.example.com)'),
                InitOption::vpnOnly('Restrict access via NetBird VPN IP whitelisting — WARNING: this tool exists to receive a paste from an external, unauthenticated partner; --vpn-only blocks exactly that. Only use it for an internal-scratchpad-only install.'),
                InitOption::force(),
                InitOption::proxied(),
            ],
            ClusterTool::ZITADEL => [
                InitOption::context(),
                InitOption::domain('Base domain OR full host for Zitadel (example.com → prefix.example.com)'),
                InitOption::value('admin-email', 'Console admin login email (default: your operator email, or admin@<host>)'),
                InitOption::flag('no-plex', 'Bypass Plex Commons and bundle a dedicated Postgres'),
                InitOption::vpnOnly(),
                InitOption::force(),
                InitOption::proxied(),
            ],
            default => [],
        };
    }

    /**
     * The fields a person is asked, without the command's own mechanics.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(ClusterTool $tool, ?string $engine = null): array
    {
        return array_values(array_map(
            fn (InitOption $option): array => $option->field(),
            array_filter(self::for($tool, $engine), fn (InitOption $option): bool => ! $option->isMechanics()),
        ));
    }

    /**
     * The command's `{name:init ...}` signature, built from the spec.
     */
    public static function signature(ClusterTool $tool): string
    {
        $options = array_map(fn (InitOption $option): string => $option->signature(), self::for($tool));

        return $tool->canonicalTool()->value.':init {environment? : Environment this install targets — "local" (default) or cloud.} '.implode(' ', $options);
    }

    public static function description(ClusterTool $tool): string
    {
        return match ($tool->canonicalTool()) {
            ClusterTool::BULWARK => 'Deploy Bulwark — a JMAP webmail UI for Stalwart — into larakube-shared',
            ClusterTool::CHATWOOT => 'Deploy the Chatwoot helpdesk stack into larakube-shared',
            ClusterTool::DIRECTUS => 'Deploy a Directus stack (Postgres + Redis + SeaweedFS) into larakube-shared',
            ClusterTool::DOCUMENSO => 'Deploy the Documenso electronic signature stack into larakube-shared',
            ClusterTool::EXTERNAL_DNS => 'Deploy an ExternalDNS instance for one or more Cloudflare zones sharing a token',
            ClusterTool::FORGEJO => 'Deploy the cluster-wide Forgejo forge, CI/CD runner, and package registry',
            ClusterTool::GLITCHTIP => 'Deploy the cluster-wide GlitchTip error tracking stack into larakube-shared',
            ClusterTool::GRAFANA => 'Deploy the cluster-wide monitoring stack (Grafana, Prometheus, Loki, Tempo) into larakube-shared',
            ClusterTool::HEADLAMP => 'Deploy the CNCF Headlamp Kubernetes web control plane into larakube-shared',
            ClusterTool::KUMA => 'Deploy the cluster-wide Uptime Kuma status page stack into larakube-shared',
            ClusterTool::KUTT => 'Deploy the Kutt link shortener stack into larakube-shared',
            ClusterTool::LIVEKIT => 'Deploy the shared LiveKit SFU (Meet) into larakube-shared',
            ClusterTool::MATRIX => 'Deploy the Matrix / Synapse chat stack into larakube-shared',
            ClusterTool::METABASE => 'Deploy the Metabase BI stack into larakube-shared',
            ClusterTool::N8N => 'Deploy the n8n workflow automation stack into larakube-shared',
            ClusterTool::NETBIRD => 'Deploy the cluster-wide NetBird VPN stack into larakube-vpn',
            ClusterTool::OCIS => 'Deploy the oCIS cloud storage and sync stack into larakube-shared',
            ClusterTool::OPENBAO => 'Deploy OpenBao secrets manager & External Secrets Operator into larakube-secrets',
            ClusterTool::OUTLINE => 'Deploy the Outline wiki / knowledge base stack into larakube-shared',
            ClusterTool::PENPOT => 'Deploy the Penpot design & prototyping suite into larakube-shared',
            ClusterTool::PLANKA => 'Deploy the Planka task management stack into larakube-shared',
            ClusterTool::PLAUSIBLE => 'Deploy the Plausible web analytics stack into larakube-shared',
            ClusterTool::POCKETBASE => 'Deploy a PocketBase stack (Embedded SQLite) into larakube-shared',
            ClusterTool::SENDREC => 'Deploy the Sendrec async video platform stack into larakube-shared',
            ClusterTool::STALWART => 'Deploy the Stalwart mail server (SMTP/IMAP/JMAP) into larakube-shared',
            ClusterTool::TEABLE => 'Deploy Teable (spreadsheet database) into larakube-shared',
            ClusterTool::TWENTY => 'Deploy the Twenty CRM stack into larakube-shared',
            ClusterTool::UMAMI => 'Deploy the Umami web analytics stack into larakube-shared',
            ClusterTool::VAULTWARDEN => 'Deploy the cluster-wide Vaultwarden team password manager into larakube-vault',
            ClusterTool::WINDMILL => 'Deploy the Windmill developer workflow platform into larakube-shared',
            ClusterTool::YOPASS => 'Deploy Yopass (secure, one-time-read paste sharing) into larakube-shared',
            ClusterTool::ZITADEL => 'Deploy Zitadel — a self-hosted OIDC/SAML identity provider — into its own larakube-sso namespace',
            default => 'Deploy '.$tool->getLabel().' into the cluster',
        };
    }
}
