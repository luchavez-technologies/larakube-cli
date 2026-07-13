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
            self::GITEA => 'k8s.gitea.shared',
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
        return "{$this->hostPrefix()}.{$domain}";
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
            self::GRAFANA, self::UPTIME_KUMA, self::VAULT, self::VPN, self::ERRORS, self::SECRETS, self::GITEA => false,
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
            self::SECRETS => 'Infisical',
            self::GITEA => 'Gitea',
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
            self::SECRETS => 'deployment infisical-backend -n larakube-secrets',
            self::GITEA => 'deployment gitea -n larakube-shared',
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
            self::SECRETS => 'Refreshing Infisical ingress...',
            self::GITEA => 'Refreshing Gitea ingress...',
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
}
