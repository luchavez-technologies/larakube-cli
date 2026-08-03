<?php

namespace App\Traits;

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Enums\SharedClusterService;

/**
 * Open/close a shared tool's raw L4 ports across BOTH firewall layers a
 * self-managed VPS has: the cloud edge (a dedicated per-tool firewall) and the
 * host's own UFW. Opening only one is the same as opening neither — the packet
 * is dropped either way.
 *
 * HTTP tools never come here: they ride Traefik on 443, which the base firewall
 * already allows. This is only for tools that bind a port of their own —
 * Stalwart's SMTP/IMAP listeners, Forgejo's SSH listener — via a LoadBalancer
 * Service. Such a Service happily reports an EXTERNAL-IP while every packet to
 * it is dropped upstream, so "the manifest looks right" proves nothing.
 *
 * Which ports belong to which tool is declared once, on
 * SharedClusterService::firewallPorts(). A tool with no declared ports is a
 * no-op here, so this can be called unconditionally from any install/teardown
 * path.
 */
trait ManagesToolFirewallPorts
{
    use InteractsWithRemoteSsh, ManagesCloudFirewall;

    /**
     * The cloud target backing this environment, or null when there is nothing
     * to open/close ports on (local, or no saved cloud IP).
     */
    protected function toolFirewallCloud(string $env): ?CloudData
    {
        if ($env === 'local') {
            return null;
        }

        $projectPath = getcwd();
        if (! file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)) {
            return null;
        }

        $cloud = ConfigData::loadFromFile($projectPath)->getCloud($env);

        return ($cloud && $cloud->ip) ? $cloud : null;
    }

    /**
     * Open a tool's ports at the cloud edge and in the host UFW. Best-effort:
     * a failure never fails the install, it prints the manual fix — the tool
     * itself is already deployed and working by this point, just unreachable.
     */
    protected function openToolPorts(SharedClusterService $service, string $env): void
    {
        $ports = $service->firewallPorts();
        $cloud = $this->toolFirewallCloud($env);

        if ($ports === [] || $cloud === null) {
            return;
        }

        $label = $service->label();

        // 1. Cloud edge — skipped silently on a non-cloud host / when no token.
        if ($this->ensureCloudFirewallPorts($this->firewallKey($service), $cloud->ip, $ports)) {
            $this->laraKubeInfo("Opened {$label} ports on the DigitalOcean cloud firewall.");
        }

        // 2. Host UFW over SSH (prefers the VPN overlay IP when one is recorded).
        if (! $this->canReachHost($cloud)) {
            return;
        }

        $script = "set -e\n".collect($ports)->map(fn (int $p) => "ufw allow {$p}/tcp")->implode("\n")."\nufw reload";

        $this->laraKubeInfo($this->runHostUfw($cloud, $script)
            ? "Opened {$label} ports in the host UFW firewall."
            : 'Could not open UFW over SSH — do it manually: ufw allow '.implode(',', $ports).'/tcp');
    }

    /**
     * Reverse openToolPorts() on teardown. A tool that is gone but whose ports
     * are still open is a real exposure, so this is best-effort but always
     * attempted.
     */
    protected function closeToolPorts(SharedClusterService $service, string $env): void
    {
        $ports = $service->firewallPorts();
        $cloud = $this->toolFirewallCloud($env);

        if ($ports === [] || $cloud === null) {
            return;
        }

        $this->removeCloudFirewall($this->firewallKey($service), $cloud->ip);

        if (! $this->canReachHost($cloud)) {
            return;
        }

        $script = collect($ports)
            ->map(fn (int $p) => "ufw delete allow {$p}/tcp 2>/dev/null || true")
            ->implode("\n")."\nufw reload || true";

        $this->runHostUfw($cloud, $script);
    }

    /**
     * Name segment for the dedicated cloud firewall (`larakube-<key>-fw-<id>`).
     * hostPrefix() rather than the enum value so the firewall reads the same as
     * the host it protects — `larakube-git-fw-*` for `git.example.com`.
     * hostPrefix() defaults to the enum value, so MAIL is unchanged and existing
     * `larakube-mail-fw-*` firewalls are still found rather than orphaned.
     */
    protected function firewallKey(SharedClusterService $service): string
    {
        return $service->hostPrefix();
    }

    /** SSH key present and usable for this host? */
    protected function canReachHost(CloudData $cloud): bool
    {
        $key = $cloud->key ? str_replace('~', home_path(), $cloud->key) : null;

        return ($cloud->vpnIp ?: $cloud->ip) !== null && $key !== null && file_exists($key);
    }

    /** Run a UFW script on the host over SSH. */
    protected function runHostUfw(CloudData $cloud, string $script): bool
    {
        return $this->runRemoteCommand(
            $cloud->user ?? 'larakube',
            $cloud->vpnIp ?: $cloud->ip,
            $cloud->port ?? 22,
            str_replace('~', home_path(), (string) $cloud->key),
            $script,
        );
    }
}
