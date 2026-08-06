<?php

namespace App\Enums;

/**
 * The cluster-wide, TLD-carrying shared services that live OUTSIDE any project's
 * namespace and are reconciled together on every local `up`. Each case owns
 * everything the generic reconciler needs (template, presence guard, namespace),
 * so adding a new shared global — Uptime Kuma, a status page, etc. — is a single
 * case here with no bespoke patching logic anywhere else.
 *
 * The recurring problem this solves: `config:tld` only rewrites local config, so
 * any shared Ingress whose host carries the TLD goes stale (console.kube 200,
 * console.localhost 404) until re-applied. `up` is the single propagation point;
 * see InteractsWithTraefik::reconcileSharedCluster().
 */
enum SharedClusterService: string
{
    /**
     * The blade view (rendered with a resolved $host) re-applied per environment.
     * For always-on services this is the full manifest; for install-gated ones
     * it's just the Ingress partial — the rest is written at the service's own
     * install point, only the host-carrying ingress needs re-pointing.
     */
    public function template(): string
    {
        return match ($this) {
            self::MAILPIT => 'k8s.mailpit.shared',
            self::TRAEFIK_DASHBOARD => 'k8s.traefik-dashboard',
            self::CONSOLE => 'k8s.console-ingress',
            self::GRAFANA => 'k8s.monitoring.grafana-ingress',
            self::UPTIME_KUMA => 'k8s.uptime.ingress',
            self::VAULT => 'k8s.vault.ingress',
            self::VPN => 'k8s.vpn.ingress',
            self::ERRORS => 'k8s.errors.ingress',
            self::SECRETS => 'k8s.secrets.ingress',
            self::GITEA => 'k8s.git.forgejo',
            self::FLOW => 'k8s.flow.ingress',
            self::SHEET => 'k8s.sheet.ingress',
            self::INSIGHTS => 'k8s.insights.ingress',
            self::MAIL => 'k8s.mail.ingress',
            self::DESK => 'k8s.desk.ingress',
            self::CHAT => 'k8s.chat.ingress',
            self::SSO => 'k8s.sso.ingress',
            self::WEBMAIL => 'k8s.webmail.ingress',
            self::NOTES => 'k8s.notes.ingress',
            self::DRIVE => 'k8s.drive.ingress',
            self::ANALYTICS => 'k8s.analytics.ingress',
            self::TASKS => 'k8s.tasks.ingress',

            self::SIGN => 'k8s.sign.ingress',
            self::SUPPORT => 'k8s.support.ingress',
            self::LINK => 'k8s.link.ingress',
            self::CRM => 'k8s.crm.ingress',
            self::DATA => 'k8s.data.ingress',
            self::RECORD => 'k8s.record.ingress',
            self::DASHBOARD => 'k8s.dashboard.ingress',
        };
    }

    public function templatePayload(): array
    {
        return match ($this) {
            // Detect the installed Flow engine. Short timeout so that if the
            // current kube-context is slow/unreachable this degrades to the
            // default engine instead of blocking (default Process timeout is 60s).
            self::FLOW => [
                'engine' => trim(\Illuminate\Support\Facades\Process::timeout(10)->run('kubectl get deployment flow-windmill -n larakube-shared --ignore-not-found 2>/dev/null')->output()) !== '' ? 'windmill' : 'n8n',
            ],
            self::DRIVE => ['engine' => 'ocis'],
            self::TASKS => [
                'engine' => 'planka',
            ],
            default => [],
        };
    }

    /**
     * The host's leftmost label (subdomain). Combined with a per-environment
     * cluster domain to form the full ingress host — locally that domain is the
     * dev TLD ({prefix}.kube), on a cloud cluster it's the env's real domain
     * ({prefix}.example.com). Distinct from value() because TRAEFIK_DASHBOARD's
     * value is the manifest name, not the host label.
     */
    public function hostPrefix(): string
    {
        return match ($this) {
            self::TRAEFIK_DASHBOARD => 'traefik',
            self::UPTIME_KUMA => 'status',
            self::GITEA => 'git',
            self::FLOW => 'flow',
            self::SHEET => 'sheet',
            self::DRIVE => 'drive',
            self::INSIGHTS => 'insights',
            self::MAIL => 'send',
            self::VAULT => 'vault',
            default => $this->value,
        };
    }

    /**
     * Build this service's full ingress host from a resolved cluster domain.
     * The caller owns domain resolution (local TLD vs the env's real domain from
     * EnvironmentData.hosts) so the enum stays free of project/cluster context.
     */
    public function hostFor(string $domain): string
    {
        $prefix = $this->hostPrefix();
        if ($prefix !== '' && str_starts_with($domain, "{$prefix}.")) {
            return $domain;
        }

        return "{$prefix}.{$domain}";
    }

    /**
     * Whether this service only ever belongs on the local dev cluster. Mailpit
     * (catch-all SMTP for dev), the Console (a local dev tool), and the Traefik
     * dashboard (not exposed on prod) are local-only; Grafana — and any future
     * cluster-wide metrics/status/observability UI that replaces it — also
     * belongs on cloud clusters (monitor:init runs everywhere).
     *
     * Declared per case rather than `$this !== GRAFANA` so swapping the metrics
     * UI, or adding a new cloud-eligible global (Uptime Kuma, a status page), is
     * a local edit to that case's arm — the capability travels with the case.
     */
    public function isLocalOnly(): bool
    {
        return match ($this) {
            self::GRAFANA, self::UPTIME_KUMA, self::VAULT, self::VPN, self::ERRORS, self::SECRETS, self::GITEA, self::FLOW, self::SHEET, self::DRIVE, self::INSIGHTS, self::MAIL, self::DESK, self::CHAT, self::SSO, self::WEBMAIL, self::NOTES, self::ANALYTICS, self::TASKS, self::SIGN, self::SUPPORT, self::LINK, self::CRM, self::DATA, self::RECORD, self::DASHBOARD => false,
            default => true,
        };
    }

    /** Whether this service should be reconciled for the given environment. */
    public function targetsEnvironment(string $environment): bool
    {
        return $environment === 'local' || ! $this->isLocalOnly();
    }

    /** Human label for host prompts and status output. */
    public function label(): string
    {
        return match ($this) {
            self::MAILPIT => 'Mailpit',
            self::TRAEFIK_DASHBOARD => 'Traefik dashboard',
            self::CONSOLE => 'LaraKube Console',
            self::GRAFANA => 'Grafana',
            self::UPTIME_KUMA => 'Uptime Kuma',
            self::VAULT => 'Vaultwarden',
            self::VPN => 'NetBird VPN',
            self::ERRORS => 'GlitchTip',
            self::SECRETS => 'OpenBao',
            self::GITEA => 'Forgejo',
            self::FLOW => 'n8n',
            self::SHEET => 'Sheet',
            self::DRIVE => 'Drive',
            self::INSIGHTS => 'Metabase',
            self::MAIL => 'Stalwart',
            self::DESK => 'FreeScout',
            self::CHAT => 'Team Chat (Matrix)',
            self::SSO => 'Zitadel',
            self::WEBMAIL => 'Bulwark',
            self::NOTES => 'Outline',
            self::ANALYTICS => 'Umami',
            self::TASKS => 'Planka',

            self::SIGN => 'Documenso',
            self::SUPPORT => 'Chatwoot',
            self::LINK => 'Kutt',
            self::CRM => 'Twenty',
            self::DATA => 'Directus',
            self::RECORD => 'Sendrec',
            self::DASHBOARD => 'Headlamp Dashboard',
        };
    }

    /**
     * kubectl selector for the resource whose presence means "this service is
     * installed". null = always reconcile (and auto-create namespace() first).
     * Install-gated services are only re-pointed when already present — `up`
     * never auto-installs them, so declining a service stays declined.
     */
    public function presenceProbe(): ?string
    {
        return match ($this) {
            // Traefik is core infra, not an opt-in install — `up` always installs
            // it BEFORE reconciling shared services, so a probe on its Service
            // never actually gates anything here; treat the dashboard as always-on
            // (like Mailpit) instead of vestigial install-gated indirection.
            self::MAILPIT, self::TRAEFIK_DASHBOARD => null,
            self::CONSOLE => 'namespace larakube-system',
            self::GRAFANA => 'deployment prometheus -n larakube-shared',
            self::UPTIME_KUMA => 'deployment uptime-kuma -n larakube-shared',
            self::VAULT => 'deployment vaultwarden -n larakube-vault',
            self::VPN => 'deployment netbird-management -n larakube-vpn',
            self::ERRORS => 'deployment glitchtip-web -n larakube-shared',
            self::SECRETS => 'deployment openbao-backend -n larakube-secrets',
            self::GITEA => 'deployment forgejo -n larakube-shared',
            self::FLOW => 'deployment -l "app in (flow-n8n, flow-windmill)" -n larakube-shared',
            self::SHEET => 'deployment sheet-teable -n larakube-shared',
            self::DRIVE => 'deployment drive-ocis -n larakube-shared',
            self::INSIGHTS => 'deployment insights-metabase -n larakube-shared',
            self::MAIL => 'deployment stalwart -n larakube-shared',
            self::DESK => 'deployment desk-freescout -n larakube-shared',
            self::CHAT => 'deployment chat-synapse -n larakube-shared',
            self::SSO => 'deployment sso-zitadel -n larakube-sso',
            self::WEBMAIL => 'deployment webmail-bulwark -n larakube-shared',
            self::NOTES => 'deployment notes-outline -n larakube-shared',
            self::ANALYTICS => 'deployment analytics-umami -n larakube-shared',
            self::TASKS => 'deployment tasks-planka -n larakube-shared',

            self::SIGN => 'deployment sign-documenso -n larakube-shared',
            self::SUPPORT => 'deployment support-chatwoot -n larakube-shared',
            self::LINK => 'deployment link-kutt -n larakube-shared',
            self::CRM => 'deployment crm-twenty -n larakube-shared',
            self::DATA => 'deployment data-directus -n larakube-shared',
            self::RECORD => 'deployment record-sendrec -n larakube-shared',
            self::DASHBOARD => 'deployment dashboard-headlamp -n larakube-shared',
        };
    }

    /**
     * L4 ports this service needs opened at the cloud edge + host firewall on a
     * single-node VPS (klipper binds them via hostPort, but the DO cloud
     * firewall and UFW default-deny them). Empty = HTTP-only, rides Traefik on
     * 443 and needs nothing. Managed clusters (DOKS) expose L4 via a real cloud
     * LoadBalancer, so this only matters for the VPS/klipper path.
     *
     * Each entry is either a bare int (single TCP port — the common case) or a
     * "<port-or-range>/<protocol>" string for anything else, e.g. "3478/udp" or
     * "49160-49179/udp" for a range. ManagesToolFirewallPorts normalizes both
     * shapes before handing them to a CloudFirewallDriver / the host UFW.
     *
     * @return array<int, int|string>
     */
    public function firewallPorts(): array
    {
        return match ($this) {
            self::MAIL => [25, 465, 587, 993, 4190],
            // Forgejo's SSH listener, for `git clone ssh://git@<host>:2222/…`.
            // Deliberately not 22: that's the node's own sshd, and the LaraKube
            // hardening step allows it for admin access only.
            self::GITEA => [2222],
            // Coturn's STUN/TURN listener (both transports) + its relay range,
            // and LiveKit's single-port RTC mux. Keep this in lockstep with the
            // port numbers hardcoded in resources/views/k8s/chat/matrix.blade.php
            // (turnserver.conf's min-port/max-port, livekit.yaml's rtc.udp_port).
            self::CHAT => [3478, '3478/udp', '49160-49179/udp', '7882/udp', 7881],
            default => [],
        };
    }

    /** Namespace to auto-create for always-on services (presenceProbe() === null). */
    public function namespace(): ?string
    {
        return match ($this) {
            self::MAILPIT => 'larakube-shared',
            // Not strictly needed — setupTraefik() always creates the 'traefik'
            // namespace first in the normal flow — but declaring it keeps this
            // always-on service consistent/self-contained like every other one,
            // and the create is idempotent if the namespace already exists.
            self::TRAEFIK_DASHBOARD => 'traefik',
            default => null,
        };
    }

    /**
     * Deployment env vars to re-sync to the current $host after the manifest is
     * applied. For services whose reconcile only re-applies the host-carrying
     * Ingress partial but whose Deployment ALSO bakes the host into env (the
     * Console's APP_URL/ASSET_URL), a `config:tld` change would otherwise
     * re-point the ingress yet leave the Deployment serving asset/URLs on the
     * old host until its own installer (`console --update`) re-renders. The
     * generic reconciler applies these via `kubectl set env` (idempotent — only
     * rolls out when a value actually changes). null = nothing to sync.
     *
     * @return array{deployment: string, namespace: string, env: array<string, string>}|null
     */
    public function deploymentEnvSync(string $host): ?array
    {
        return match ($this) {
            self::CONSOLE => [
                'deployment' => 'larakube-dashboard',
                'namespace' => 'larakube-system',
                'env' => [
                    'APP_URL' => "https://{$host}",
                    'ASSET_URL' => "https://{$host}",
                ],
            ],
            default => null,
        };
    }

    /** Spinner label shown while this service is reconciled. */
    public function reconcileLabel(): string
    {
        return match ($this) {
            self::MAILPIT => 'Ensuring shared Mailpit (catch-all SMTP) is running...',
            self::TRAEFIK_DASHBOARD => 'Refreshing Traefik dashboard ingress...',
            self::CONSOLE => 'Refreshing LaraKube Console ingress...',
            self::GRAFANA => 'Refreshing Grafana ingress...',
            self::UPTIME_KUMA => 'Refreshing Uptime Kuma ingress...',
            self::VAULT => 'Refreshing Vaultwarden ingress...',
            self::VPN => 'Refreshing NetBird VPN ingress...',
            self::ERRORS => 'Refreshing GlitchTip ingress...',
            self::SECRETS => 'Refreshing OpenBao ingress...',
            self::GITEA => 'Refreshing Forgejo ingress...',
            self::FLOW => 'Refreshing Flow (n8n) ingress...',
            self::SHEET => 'Refreshing Sheet ingress...',
            self::DRIVE => 'Refreshing Drive ingress...',
            self::INSIGHTS => 'Refreshing Insights (Metabase) ingress...',
            self::MAIL => 'Refreshing Stalwart (Mail) ingress...',
            self::DESK => 'Refreshing FreeScout (Help Desk) ingress...',
            self::CHAT => 'Refreshing Matrix (Chat) ingress...',
            self::SSO => 'Refreshing Zitadel (SSO) ingress...',
            self::WEBMAIL => 'Refreshing Bulwark (Webmail) ingress...',
            self::NOTES => 'Refreshing Outline (Wiki) ingress...',
            self::ANALYTICS => 'Refreshing Umami (Analytics) ingress...',
            self::TASKS => 'Refreshing Planka (Tasks) ingress...',

            self::SIGN => 'Refreshing Documenso (Sign) ingress...',
            self::SUPPORT => 'Refreshing Chatwoot (Support) ingress...',
            self::LINK => 'Refreshing Kutt (Link) ingress...',
            self::CRM => 'Refreshing Twenty (CRM) ingress...',
            self::DATA => 'Refreshing Directus (Data) ingress...',
            self::RECORD => 'Refreshing Sendrec (Screen Recording) ingress...',
            self::DASHBOARD => 'Refreshing Headlamp (Dashboard) ingress...',
        };
    }

    /**
     * The full ingress hostname for this service on the given domain.
     * Convenience wrapper around hostFor() for code that needs the resolved host.
     */
    public function host(string $domain): string
    {
        return $this->hostFor($domain);
    }
    case MAILPIT = 'mailpit';
    case TRAEFIK_DASHBOARD = 'traefik-dashboard';
    case CONSOLE = 'console';
    case GRAFANA = 'grafana';
    case UPTIME_KUMA = 'uptime';
    case VAULT = 'vault';
    case VPN = 'vpn';
    case ERRORS = 'errors';
    case SECRETS = 'secrets';
    case GITEA = 'gitea';
    case FLOW = 'flow';
    case SHEET = 'sheet';
    case INSIGHTS = 'insights';
    case MAIL = 'mail';
    case DESK = 'desk';
    case CHAT = 'chat';
    case SSO = 'sso';
    case WEBMAIL = 'webmail';
    case NOTES = 'notes';
    case DRIVE = 'drive';
    case ANALYTICS = 'analytics';
    case TASKS = 'tasks';

    case SIGN = 'sign';
    case SUPPORT = 'support';
    case LINK = 'link';
    case CRM = 'crm';
    case DATA = 'data';
    case RECORD = 'record';
    case DASHBOARD = 'dashboard';
}
