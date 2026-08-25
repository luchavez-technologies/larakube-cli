<?php

namespace App\Commands\Vpn;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class VpnRevokeCommand extends Command
{
    use InteractsWithProjectConfig, InteractsWithVpn, LaraKubeOutput;

    protected $signature = 'vpn:revoke
        {environment=local : Environment whose NetBird VPN the key belongs to}
        {--name= : Revoke the active key(s) minted for this teammate name}
        {--key-id= : Revoke one specific key by its id (see vpn:users)}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved cloud target)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Revoke a NetBird VPN setup key — the kill-switch for offboarding or a leaked key';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $config = $this->getProjectConfig();
        $kubectl = $this->vpnKubectl($this->resolveVpnContext($env, $config));
        $ns = $this->vpnNamespace();

        if (! $this->isVpnInstalled($kubectl, $ns)) {
            $this->laraKubeError("NetBird VPN isn't installed for '{$env}'.");

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

        $targets = $this->resolveRevokeTargets($keys, (string) $this->option('name'), (string) $this->option('key-id'));
        if ($targets === null) {
            return 1;
        }
        if (empty($targets)) {
            $this->laraKubeInfo("Nothing to revoke for '{$env}'.");

            return 0;
        }

        $label = count($targets) === 1 ? "'{$targets[0]['name']}'" : (count($targets).' keys');
        if (! $this->option('force') && ! confirm("Revoke {$label}?", false)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $failures = 0;
        foreach ($targets as $key) {
            $id = (string) ($key['id'] ?? '');
            if ($this->revokeVpnSetupKey($host, $pat, $key)) {
                $this->laraKubeInfo("✅ Revoked '{$key['name']}' ({$id}).");
            } else {
                $failures++;
                $this->laraKubeWarn("Could not revoke '{$key['name']}' ({$id}) — see the NetBird server output above.");
            }
        }

        return $failures > 0 ? 1 : 0;
    }

    /**
     * Which key(s) to revoke: --key-id wins (exact match, revoked or not —
     * explicit by id is always honored), else --name (every currently-active
     * key with that label, since multiple may have been minted for the same
     * person over time), else an interactive picker. Null means "already
     * reported an error, exit 1"; empty array means "nothing to do, exit 0".
     *
     * @param  array<int, array<string, mixed>>  $keys
     * @return array<int, array<string, mixed>>|null
     */
    protected function resolveRevokeTargets(array $keys, string $name, string $keyId): ?array
    {
        if ($keyId !== '') {
            $match = array_values(array_filter($keys, fn ($k) => ($k['id'] ?? '') === $keyId));
            if (empty($match)) {
                $this->laraKubeError("No setup key found with id '{$keyId}'.");

                return null;
            }

            return $match;
        }

        if ($name !== '') {
            $match = array_values(array_filter($keys, fn ($k) => ($k['name'] ?? '') === $name && ! ($k['revoked'] ?? false)));
            if (empty($match)) {
                $this->laraKubeError("No active setup key found for '{$name}'.");

                return null;
            }

            return $match;
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Pass --name= or --key-id= to revoke a key.');

            return null;
        }

        $active = array_values(array_filter($keys, fn ($k) => ! ($k['revoked'] ?? false)));
        if (empty($active)) {
            return [];
        }

        // Keyed by a non-numeric-looking string, not the plain 0-based index
        // array_values($active) naturally produces — a sequential-int-keyed
        // $options array is indistinguishable from a list to
        // array_is_list(), so Laravel Prompts' select() returns the LABEL
        // text instead of the key, and $active[$chosen] below 500s with
        // "Undefined array key" on the label string itself. Confirmed live
        // 2026-08-25.
        $options = [];
        $byOptionKey = [];
        foreach ($active as $key) {
            $optionKey = 'key-'.($key['id'] ?? '');
            $limit = (int) ($key['usage_limit'] ?? 0);
            $options[$optionKey] = ($key['name'] ?? '?').' — '.($key['key'] ?? '?').' (used '.($key['used_times'] ?? 0).'/'.($limit === 0 ? '∞' : $limit).')';
            $byOptionKey[$optionKey] = $key;
        }

        $chosen = select(label: 'Which setup key to revoke?', options: $options);

        return [$byOptionKey[$chosen]];
    }
}
