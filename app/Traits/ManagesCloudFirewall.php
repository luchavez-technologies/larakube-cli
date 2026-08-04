<?php

namespace App\Traits;

use App\Contracts\CloudFirewallDriver;
use App\Firewall\DigitalOceanFirewallDriver;

/**
 * Provider-agnostic orchestration for opening/closing a shared tool's L4 ports
 * at the cloud edge on a self-managed VPS. Resolves the right
 * CloudFirewallDriver by asking each CONFIGURED provider "do you own the host at
 * this IP?" — so callers never need explicit provider detection. HTTP-only tools
 * ride Traefik on 443 and need none of this; managed clusters expose L4 via a
 * cloud LoadBalancer, so they don't either.
 *
 * Today only DigitalOcean is implemented. Adding Hetzner/AWS/GCP is a drop-in:
 * write a CloudFirewallDriver and register it in cloudFirewallDrivers(). The
 * paired host-UFW opening is provider-agnostic already (any Linux VPS) and lives
 * in the caller, over SSH.
 */
trait ManagesCloudFirewall
{
    use InteractsWithGlobalConfig;

    /**
     * Every firewall driver whose provider credentials are present.
     *
     * @return array<int, CloudFirewallDriver>
     */
    protected function cloudFirewallDrivers(): array
    {
        $drivers = [
            new DigitalOceanFirewallDriver($this->getDoToken()),
            // Future: new HetznerFirewallDriver($this->getHetznerToken()),
            //         new AwsSecurityGroupDriver(...), new GcpVpcFirewallDriver(...),
        ];

        return array_values(array_filter($drivers, fn (CloudFirewallDriver $d) => $d->isConfigured()));
    }

    /**
     * The driver that owns the host at $ip plus its host id, or null when no
     * configured provider recognizes the IP (a non-cloud host / missing creds).
     *
     * @return array{0: CloudFirewallDriver, 1: string}|null
     */
    protected function resolveCloudFirewall(string $ip): ?array
    {
        foreach ($this->cloudFirewallDrivers() as $driver) {
            $hostId = $driver->findHostId($ip);
            if ($hostId !== null) {
                return [$driver, $hostId];
            }
        }

        return null;
    }

    /**
     * Ensure $ports are open at the cloud edge for the host at $ip via a
     * dedicated per-tool firewall. Returns false when no provider owns the IP —
     * the caller then relies on the host UFW alone.
     *
     * @param  array<int, array{ports: string, protocol: string}>  $ports  Already normalized — see ManagesToolFirewallPorts::normalizePortSpecs().
     */
    protected function ensureCloudFirewallPorts(string $tool, string $ip, array $ports): bool
    {
        if ($ports === []) {
            return false;
        }

        $resolved = $this->resolveCloudFirewall($ip);
        if ($resolved === null) {
            return false;
        }

        [$driver, $hostId] = $resolved;

        return $driver->openPorts($tool, $hostId, $ports);
    }

    /** Remove a tool's dedicated cloud firewall for the host at $ip. No-op if none. */
    protected function removeCloudFirewall(string $tool, string $ip): void
    {
        $resolved = $this->resolveCloudFirewall($ip);
        if ($resolved !== null) {
            [$driver, $hostId] = $resolved;
            $driver->removeFirewall($tool, $hostId);
        }
    }
}
