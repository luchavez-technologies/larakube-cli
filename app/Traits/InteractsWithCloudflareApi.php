<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;

/**
 * Thin, one-off wrapper over Cloudflare's DNS API — used for writes that
 * don't belong to ExternalDNS's ingress-reconciling model (dns:init), most
 * notably Zitadel's org-domain-verification TXT challenge. Same v4 REST
 * envelope shape ({success, result, errors}) already consumed by
 * InteractsWithBackup::createR2Bucket() — this trait exists separately
 * because DNS record management and R2 bucket management are different
 * API surfaces with different callers, not because the auth/response shape
 * differs.
 */
trait InteractsWithCloudflareApi
{
    /** The zone id for $zone, or null if the token can't see it (wrong zone, wrong token scope, or it doesn't exist). */
    protected function cloudflareZoneId(string $zone, string $token): ?string
    {
        $response = Http::withToken($token)
            ->timeout(15)
            ->get('https://api.cloudflare.com/client/v4/zones', ['name' => $zone]);

        if ($response->failed() || ($response->json('success') !== true)) {
            return null;
        }

        return $response->json('result.0.id');
    }

    /**
     * Create or update a TXT record named $name on $zoneId, with $content as
     * its value. Idempotent by $name (Cloudflare allows multiple TXT
     * records with the same name, but a domain-verification challenge only
     * ever needs one live value — an existing record with this name is
     * patched in place rather than duplicated).
     */
    protected function cloudflareUpsertTxtRecord(string $zoneId, string $token, string $name, string $content, int $ttl = 120): bool
    {
        $search = Http::withToken($token)
            ->timeout(15)
            ->get("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", [
                'type' => 'TXT',
                'name' => $name,
            ]);

        $existingId = ($search->successful() && $search->json('success') === true)
            ? $search->json('result.0.id')
            : null;

        if ($existingId !== null) {
            $update = Http::withToken($token)
                ->timeout(15)
                ->patch("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records/{$existingId}", [
                    'content' => $content,
                    'ttl' => $ttl,
                ]);

            return $update->successful() && $update->json('success') === true;
        }

        $create = Http::withToken($token)
            ->timeout(15)
            ->post("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", [
                'type' => 'TXT',
                'name' => $name,
                'content' => $content,
                'ttl' => $ttl,
            ]);

        return $create->successful() && $create->json('success') === true;
    }
}
