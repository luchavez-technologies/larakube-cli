<?php

namespace App\Contracts;

/**
 * A provider's cloud-edge firewall, abstracted to the INTENT ("open these L4
 * ports for the host at this IP") rather than any one provider's mechanism —
 * DO/Hetzner attach a firewall to an instance, AWS uses security groups, GCP
 * uses VPC rules targeted by tag. Each provider satisfies these four methods its
 * own way. Managed clusters (DOKS/EKS/GKE/AKS) expose L4 via a cloud
 * LoadBalancer, so they need no driver at all.
 */
interface CloudFirewallDriver
{
    /** True when this provider's credentials are present (else it's skipped). */
    public function isConfigured(): bool;

    /**
     * The provider's opaque host id (droplet/instance id) for a public IP, or
     * null when this provider doesn't own that IP — which is how the resolver
     * auto-detects the right provider without explicit configuration.
     */
    public function findHostId(string $ip): ?string;

    /**
     * Idempotently ensure the given ports are open at the cloud edge for
     * $hostId, on a DEDICATED per-tool firewall/security-group — never the
     * provider's IaC-managed base firewall, so a later apply can't revert it.
     *
     * @param  array<int, array{ports: string, protocol: string}>  $ports  Already
     *                                                                     normalized by ManagesToolFirewallPorts::normalizePortSpecs() —
     *                                                                     'ports' is a single port or a "start-end" range, 'protocol' is
     *                                                                     'tcp' or 'udp'.
     */
    public function openPorts(string $tool, string $hostId, array $ports): bool;

    /** Remove the dedicated per-tool firewall for $hostId. No-op if absent. */
    public function removeFirewall(string $tool, string $hostId): void;
}
