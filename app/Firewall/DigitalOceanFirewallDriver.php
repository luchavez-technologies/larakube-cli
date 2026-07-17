<?php

namespace App\Firewall;

use App\Contracts\CloudFirewallDriver;
use Illuminate\Support\Facades\Process;

/**
 * DigitalOcean cloud-firewall driver. Opens a tool's L4 ports on a DEDICATED
 * `larakube-<tool>-fw-<dropletId>` firewall — NOT the Terraform-managed base
 * firewall — because DO unions rules across every firewall attached to a
 * droplet, so a dedicated one composes cleanly and a `tofu apply` never reverts
 * it. The token is injected (the caller sources it from global config / a
 * run-only override).
 */
class DigitalOceanFirewallDriver implements CloudFirewallDriver
{
    public function __construct(private ?string $token) {}

    public function isConfigured(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    public function findHostId(string $ip): ?string
    {
        [$code, $body] = $this->api('GET', '/droplets?per_page=200');
        if ($code !== 200 || ! isset($body['droplets'])) {
            return null;
        }

        foreach ($body['droplets'] as $droplet) {
            foreach ($droplet['networks']['v4'] ?? [] as $net) {
                if (($net['type'] ?? '') === 'public' && ($net['ip_address'] ?? '') === $ip) {
                    return (string) $droplet['id'];
                }
            }
        }

        return null;
    }

    public function openPorts(string $tool, string $hostId, array $ports): bool
    {
        if ($ports === []) {
            return false;
        }

        $inbound = array_map(fn (int $p) => [
            'protocol' => 'tcp',
            'ports' => (string) $p,
            'sources' => ['addresses' => ['0.0.0.0/0', '::/0']],
        ], array_values($ports));

        $name = $this->firewallName($tool, $hostId);
        $id = $this->findFirewallId($name);

        if ($id === null) {
            // Permissive outbound so this firewall is safe even if it were ever
            // the droplet's only one (DO blocks all outbound otherwise).
            [$code] = $this->api('POST', '/firewalls', [
                'name' => $name,
                'inbound_rules' => $inbound,
                'outbound_rules' => [
                    ['protocol' => 'tcp', 'ports' => '1-65535', 'destinations' => ['addresses' => ['0.0.0.0/0', '::/0']]],
                    ['protocol' => 'udp', 'ports' => '1-65535', 'destinations' => ['addresses' => ['0.0.0.0/0', '::/0']]],
                    ['protocol' => 'icmp', 'destinations' => ['addresses' => ['0.0.0.0/0', '::/0']]],
                ],
                'droplet_ids' => [(int) $hostId],
            ]);

            return in_array($code, [200, 201, 202], true);
        }

        // Exists — reassert the rules + droplet attachment (both idempotent).
        $this->api('POST', "/firewalls/{$id}/rules", ['inbound_rules' => $inbound]);
        $this->api('POST', "/firewalls/{$id}/droplets", ['droplet_ids' => [(int) $hostId]]);

        return true;
    }

    public function removeFirewall(string $tool, string $hostId): void
    {
        $id = $this->findFirewallId($this->firewallName($tool, $hostId));
        if ($id !== null) {
            $this->api('DELETE', "/firewalls/{$id}");
        }
    }

    private function firewallName(string $tool, string $hostId): string
    {
        return "larakube-{$tool}-fw-{$hostId}";
    }

    private function findFirewallId(string $name): int|string|null
    {
        [$code, $body] = $this->api('GET', '/firewalls?per_page=200');
        if ($code !== 200) {
            return null;
        }

        foreach ($body['firewalls'] ?? [] as $fw) {
            if (($fw['name'] ?? '') === $name) {
                return $fw['id'];
            }
        }

        return null;
    }

    /**
     * Low-level DO API call via curl. Returns [httpCode, decodedBody|null].
     *
     * @param  array<string, mixed>|null  $body
     * @return array{0:int,1:array<mixed>|null}
     */
    private function api(string $method, string $path, ?array $body = null): array
    {
        if (! $this->isConfigured()) {
            return [0, null];
        }

        $cmd = 'curl -s -w '.escapeshellarg("\n%{http_code}").' -X '.escapeshellarg($method)
            .' -H '.escapeshellarg("Authorization: Bearer {$this->token}")
            .' -H '.escapeshellarg('Content-Type: application/json')
            .($body !== null ? ' --data '.escapeshellarg((string) json_encode($body)) : '')
            .' '.escapeshellarg("https://api.digitalocean.com/v2{$path}");

        $out = Process::run($cmd)->output();
        $nl = strrpos($out, "\n");
        if ($nl === false) {
            return [0, null];
        }
        $code = (int) trim(substr($out, $nl + 1));
        $decoded = json_decode(substr($out, 0, $nl), true);

        return [$code, is_array($decoded) ? $decoded : null];
    }
}
