<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

trait InteractsWithVpn
{
    use ResolvesEnvironmentContext;

    /** The dedicated namespace the NetBird VPN lives in. */
    protected function vpnNamespace(): string
    {
        return 'larakube-vpn';
    }

    /** Build the kubectl command, optionally scoped to a specific context, pinned to ~/.kube/config. */
    protected function vpnKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /**
     * The env's own kube-context — an explicit --context always wins, otherwise
     * the CHOSEN environment's saved cloud target (never the ambient
     * current-context, which would otherwise silently target whatever cluster
     * happened to be active regardless of the environment argument/prompt).
     */
    protected function resolveVpnContext(string $env, ?ConfigData $config): ?string
    {
        $contextOption = (string) ($this->option('context') ?? '');
        if ($contextOption !== '') {
            return $contextOption;
        }

        return $config ? $this->environmentContextOrCurrent($config, $env) : null;
    }

    /** NetBird management Deployment present? A cheap "is NetBird installed" probe. */
    protected function isVpnInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment netbird-management -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /**
     * Read the reusable setup key `vpn:init` bootstrapped, from the k8s Secret
     * it wrote (`kubectl create secret ... netbird-admin`). One bootstrap,
     * shared by every teammate with kubectl access — used by both `vpn:join`
     * (this developer's own machine) and `cloud:harden` (the VPS host itself).
     */
    protected function fetchVpnSetupKey(string $kubectl, string $ns): ?string
    {
        $encoded = trim(Process::run(
            "{$kubectl} get secret netbird-admin -n {$ns} -o jsonpath='{.data.setup-key}'",
        )->output());

        if ($encoded === '') {
            return null;
        }

        $key = base64_decode($encoded, true);

        return $key !== false && $key !== '' ? $key : null;
    }

    /**
     * Read the NetBird owner's Personal Access Token from the same k8s Secret
     * `vpn:init` bootstrapped (`netbird-admin`), same shape as
     * fetchVpnSetupKey() but the `pat` field instead of `setup-key`. Used to
     * call NetBird's REST API (minting/listing/revoking setup keys) on the
     * operator's behalf — vpn:grant/vpn:revoke/vpn:users.
     */
    protected function fetchVpnPat(string $kubectl, string $ns): ?string
    {
        $encoded = trim(Process::run(
            "{$kubectl} get secret netbird-admin -n {$ns} -o jsonpath='{.data.pat}'",
        )->output());

        if ($encoded === '') {
            return null;
        }

        $pat = base64_decode($encoded, true);

        return $pat !== false && $pat !== '' ? $pat : null;
    }

    /**
     * Read-only NetBird host for an env: local → vpn.{dev tld}; a cloud env →
     * the host persisted in .larakube.json (null when not configured yet). Never
     * prompts or persists.
     */
    protected function resolveVpnHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::VPN;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /**
     * Mint a NetBird setup key via the REST API — vpn:grant. Returns the
     * decoded response (its `key` field is plaintext, only ever returned on
     * create — every later GET redacts it), or null on any HTTP failure.
     *
     * @return array<string, mixed>|null
     */
    protected function mintVpnSetupKey(string $host, string $pat, string $name, bool $reusable, int $days): ?array
    {
        $response = Http::timeout(15)
            ->withHeaders(['Authorization' => "Token {$pat}"])
            ->post("https://{$host}/api/setup-keys", [
                'name' => $name,
                'type' => 'reusable',
                'expires_in' => $days * 86400,
                'usage_limit' => $reusable ? 0 : 1,
            ]);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) && ! empty($data['key']) ? $data : null;
    }

    /**
     * List setup keys via the REST API — vpn:users/vpn:revoke. The `key`
     * field is redacted server-side on every entry (e.g. "2A7A9****") — only
     * mintVpnSetupKey()'s create response ever holds the plaintext value.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function listVpnSetupKeys(string $host, string $pat): ?array
    {
        $response = Http::timeout(15)
            ->withHeaders(['Authorization' => "Token {$pat}"])
            ->get("https://{$host}/api/setup-keys");

        if ($response->failed()) {
            return null;
        }

        $keys = $response->json();

        return is_array($keys) ? $keys : null;
    }

    /**
     * List connected peers via the REST API — vpn:users. Null on any HTTP failure.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function listVpnPeers(string $host, string $pat): ?array
    {
        $response = Http::timeout(15)
            ->withHeaders(['Authorization' => "Token {$pat}"])
            ->get("https://{$host}/api/peers");

        if ($response->failed()) {
            return null;
        }

        $peers = $response->json();

        return is_array($peers) ? $peers : null;
    }

    /**
     * Revoke one setup key — vpn:revoke. NetBird's PUT requires the FULL
     * object back (a partial {"revoked":true} 422s with "setup key
     * autogroups field is invalid" — empirically confirmed, undocumented),
     * so this re-sends every writable field from the list entry with
     * `revoked` flipped. expires_in is recomputed from the entry's absolute
     * `expires` since the list response never carries the original relative
     * value.
     *
     * @param  array<string, mixed>  $key  one entry from listVpnSetupKeys()
     */
    protected function revokeVpnSetupKey(string $host, string $pat, array $key): bool
    {
        $expiresIn = max(60, strtotime((string) ($key['expires'] ?? '')) - time());

        return Http::timeout(15)
            ->withHeaders(['Authorization' => "Token {$pat}"])
            ->put("https://{$host}/api/setup-keys/".($key['id'] ?? ''), [
                'name' => $key['name'] ?? '',
                'type' => $key['type'] ?? 'reusable',
                'expires_in' => $expiresIn,
                'usage_limit' => $key['usage_limit'] ?? 0,
                'revoked' => true,
                'auto_groups' => $key['auto_groups'] ?? [],
                'ephemeral' => $key['ephemeral'] ?? false,
                'allow_extra_dns_labels' => $key['allow_extra_dns_labels'] ?? false,
            ])
            ->successful();
    }

    /**
     * Resolve the NetBird VPN's access details for display.
     * Returns null when NetBird isn't installed.
     *
     * @return array{host: ?string, label: string}|null
     */
    protected function vpnAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->vpnKubectl($context);
        $ns = $this->vpnNamespace();

        if (! $this->isVpnInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveVpnHostReadOnly($env, $config),
            'label' => 'NetBird VPN',
        ];
    }
}
