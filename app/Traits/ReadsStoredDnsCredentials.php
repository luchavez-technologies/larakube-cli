<?php

namespace App\Traits;

use App\Enums\DnsProvider;
use Illuminate\Support\Facades\Process;

/**
 * Every DNS credential `tool:init --tool=external-dns` has stored, across every provider, keyed
 * by group slug — so `tls:init` (and `dns:init` re-runs) can reuse one
 * instead of asking for the same credential twice, the same way it always
 * has for Cloudflare. Deliberately separate from ReadsStoredCloudflareTokens
 * (which stays Cloudflare-only): several OTHER commands (mail:dns, sso:org,
 * tls:show, cloud:proxy) only ever need a Cloudflare token and must not be
 * handed a Route53 credential by mistake.
 *
 * The using class provides readClusterSecretKey() (ReadsClusterSecrets).
 */
trait ReadsStoredDnsCredentials
{
    /**
     * @return array<string, array{provider: DnsProvider, data: array<string, string>}>
     */
    protected function storedDnsCredentials(string $kubectl, string $ns): array
    {
        $names = trim(Process::run(
            "{$kubectl} get secret -n {$ns} -o name --no-headers --ignore-not-found",
        )->output());

        $credentials = [];

        foreach (preg_split('/\s+/', $names) ?: [] as $raw) {
            $name = str_replace('secret/', '', trim($raw));
            if ($name === '') {
                continue;
            }

            foreach (DnsProvider::cases() as $provider) {
                $prefix = $provider->credentialSecretPrefix();
                if (! str_starts_with($name, $prefix)) {
                    continue;
                }

                $slug = substr($name, strlen($prefix));
                $data = [];
                foreach ($provider->credentialSecretKeys() as $key) {
                    $value = $this->readClusterSecretKey($kubectl, $ns, $name, $key);
                    if ($value === null || $value === '') {
                        continue 2; // an incomplete secret isn't a usable credential
                    }
                    $data[$key] = $value;
                }

                $credentials[$slug] = ['provider' => $provider, 'data' => $data];

                break;
            }
        }

        return $credentials;
    }
}
