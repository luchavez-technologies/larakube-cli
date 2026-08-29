<?php

namespace App\Commands\Vpn;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithToolRegistry;

class VpnRemoveCommand extends AbstractToolRemoveCommand
{
    use InteractsWithToolRegistry;

    protected function tool(): ClusterTool
    {
        return ClusterTool::VPN;
    }

    protected function teardownWarning(string $env): array
    {
        return [
            "The NetBird VPN stack will be REMOVED from '{$env}':",
            'Deployment, Services, Secrets — and the whole larakube-vpn namespace',
            'Every peer is disconnected, and any tool deployed with --vpn-only becomes unreachable.',
        ];
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        // Captured before the namespace goes: the Ingress is what ExternalDNS
        // watches, so this is the host whose record is about to disappear.
        $host = $this->getToolHost($kubectl, ClusterTool::VPN);

        $removed = $this->removeNamespace(
            'Removing NetBird VPN namespace...',
            $kubectl,
            $namespace,
        );

        if ($removed && $host !== null && $host !== '') {
            $this->warnResolverCacheAfterTeardown($host);
        }

        return $removed;
    }

    /**
     * Tell the operator their resolver will now cache this host's ABSENCE.
     *
     * Deliberately does NOT hand them a curl check to run here: right after a
     * teardown the host genuinely does not resolve, and a check that correctly
     * fails reads as a new problem. The two states are told apart by PUBLIC DNS
     * — no record means correctly gone, a record that resolves publicly but not
     * locally means the cache went stale — which is the test vpn:init already
     * makes before it says anything about flushing.
     *
     * Removing the namespace removes the Ingress, ExternalDNS then removes the
     * DNS record, and the machine running this command caches that gap — on
     * macOS as a NAT64-synthesised IPv6 with no IPv4. The record returns on the
     * next vpn:init, but the cache does not expire with it, so every later
     * command fails to reach NetBird while `dig` still reports DNS as healthy.
     * Confirmed live 2026-08-29 across several teardown/init cycles, each one
     * surfacing as a gateway stuck in CreateContainerConfigError — three steps
     * removed from the actual cause.
     *
     * Only NetBird warns today. Every tool with an Ingress has the same
     * exposure; see plans/active/resolver-cache-after-teardown.md.
     */
    protected function warnResolverCacheAfterTeardown(string $host): void
    {
        $this->newLine();
        $this->line('  <fg=gray>Note: '.$host.' no longer resolves — that is correct, its DNS record is gone.</>');
        $this->newLine();
        $this->line('  <fg=yellow>⚠ If</> <fg=blue>vpn:init</> <fg=yellow>later cannot reach it, flush your resolver cache first.</>');
        $this->line('  <fg=gray>Machines cache the absence you just created, and on macOS answer with a</>');
        $this->line('  <fg=gray>NAT64 IPv6 that nothing can connect through — past the point the record</>');
        $this->line('  <fg=gray>comes back. dig keeps reporting DNS as healthy throughout, because it</>');
        $this->line('  <fg=gray>queries DNS directly and never sees that cache.</>');
        $this->newLine();
        $this->line('  <fg=blue>  sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder</>  <fg=gray>(macOS)</>');
        $this->line('  <fg=blue>  sudo resolvectl flush-caches</>                                  <fg=gray>(systemd-resolved)</>');
        $this->newLine();
    }
}
