<?php

namespace App\Commands\Vpn;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class VpnUsersCommand extends Command
{
    use InteractsWithProjectConfig, InteractsWithVpn, LaraKubeOutput;

    protected $signature = 'vpn:users
        {environment=local : Environment whose NetBird VPN to list}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved cloud target)}';

    protected $description = 'List NetBird VPN setup keys and connected peers for an environment';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $config = $this->getProjectConfig();
        $kubectl = $this->vpnKubectl($this->resolveVpnContext($env, $config));
        $ns = $this->vpnNamespace();

        if (! $this->isVpnInstalled($kubectl, $ns)) {
            $this->laraKubeError("NetBird VPN isn't installed for '{$env}'.");
            $this->line("  Run <fg=yellow>larakube vpn:init {$env}</> first.");

            return 1;
        }

        $host = $this->resolveVpnHostReadOnly($env, $config);
        if (! $host) {
            $this->laraKubeError("No NetBird VPN host configured for '{$env}'.");

            return 1;
        }

        $pat = $this->fetchVpnPat($kubectl, $ns);
        if ($pat === null) {
            $this->laraKubeError("No NetBird admin token found — re-run `larakube vpn:init {$env}` to bootstrap auth.");

            return 1;
        }

        $keys = $this->listVpnSetupKeys($host, $pat);
        if ($keys === null) {
            $this->laraKubeError('Could not reach the NetBird API to list setup keys.');

            return 1;
        }

        $peers = $this->listVpnPeers($host, $pat) ?? [];

        $this->laraKubeInfo("NetBird VPN — '{$env}'");
        $this->line('  <fg=gray>Host:</> <fg=cyan>'.$host.'</>');
        $this->laraKubeNewLine();

        if (empty($keys)) {
            $this->line('  <fg=gray>No setup keys minted yet.</> Grant one: <fg=yellow>larakube vpn:grant '.$env.' --name <person></>');
        } else {
            $rows = [];
            foreach ($keys as $key) {
                $limit = (int) ($key['usage_limit'] ?? 0);
                $rows[] = [
                    $key['name'] ?? '?',
                    $key['key'] ?? '?',
                    $limit === 0 ? 'unlimited' : ($limit === 1 ? 'single-use' : "max {$limit}"),
                    ($key['used_times'] ?? 0).'/'.($limit === 0 ? '∞' : $limit),
                    $this->vpnKeyState($key),
                    isset($key['expires']) ? substr((string) $key['expires'], 0, 10) : '?',
                ];
            }
            $this->line('  <fg=green>Setup keys</>');
            table(['Name', 'Key', 'Type', 'Uses', 'State', 'Expires'], $rows);
            $this->line('  <fg=gray>Grant:</> <fg=yellow>larakube vpn:grant '.$env.' --name <person></>  <fg=gray>· Revoke:</> <fg=yellow>larakube vpn:revoke '.$env.' --name <person></>');
        }

        $this->laraKubeNewLine();

        if (empty($peers)) {
            $this->line('  <fg=gray>No peers have joined yet.</>');
        } else {
            $rows = [];
            foreach ($peers as $peer) {
                $rows[] = [
                    $peer['hostname'] ?? $peer['name'] ?? '?',
                    $peer['ip'] ?? '?',
                    $peer['os'] ?? '?',
                    ($peer['connected'] ?? false) ? '🟢 online' : '⚪ offline',
                    isset($peer['last_seen']) ? substr((string) $peer['last_seen'], 0, 19).'Z' : '?',
                ];
            }
            $this->line('  <fg=green>Connected peers</>');
            table(['Hostname', 'IP', 'OS', 'Status', 'Last seen'], $rows);
        }

        return 0;
    }

    /** A setup key's human state: valid, revoked, expired, or exhausted (usage_limit reached). */
    protected function vpnKeyState(array $key): string
    {
        if ($key['revoked'] ?? false) {
            return '🚫 revoked';
        }

        if (! ($key['valid'] ?? true)) {
            return '⌛ expired/exhausted';
        }

        return '✅ valid';
    }
}
