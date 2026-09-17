<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * The Cloudflare tokens `dns:init` stored in `larakube-shared`, one per group.
 * `tls:init` reuses them rather than asking for the same credential twice.
 *
 * The using class provides readClusterSecretKey() (ReadsClusterSecrets).
 */
trait ReadsStoredCloudflareTokens
{
    /**
     * Every stored Cloudflare token, keyed by its group slug.
     *
     * @return array<string, string>
     */
    protected function storedCloudflareTokens(string $kubectl, string $ns): array
    {
        $names = trim(Process::run(
            "{$kubectl} get secret -n {$ns} -o name --no-headers --ignore-not-found",
        )->output());

        $tokens = [];

        foreach (preg_split('/\s+/', $names) ?: [] as $name) {
            $name = str_replace('secret/', '', trim($name));

            if (! str_starts_with($name, 'cloudflare-token-')) {
                continue;
            }

            $value = $this->readClusterSecretKey($kubectl, $ns, $name, 'token');

            if ($value !== null && $value !== '') {
                $tokens[substr($name, strlen('cloudflare-token-'))] = $value;
            }
        }

        return $tokens;
    }
}
