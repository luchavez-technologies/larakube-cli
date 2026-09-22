<?php

namespace App\Traits;

use App\Http\Integrations\Cloudflare\CloudflareConnector;
use App\Http\Integrations\Cloudflare\Requests\CreateDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\GetZoneByNameRequest;
use App\Http\Integrations\Cloudflare\Requests\GetZoneSettingRequest;
use App\Http\Integrations\Cloudflare\Requests\ListDnsRecordsRequest;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use App\Http\Integrations\Cloudflare\Requests\PatchDnsRecordRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

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
    /**
     * @throws ConnectionException|FatalRequestException|RequestException|JsonException
     */
    protected function cloudflareZoneId(string $zone, string $token): ?string
    {
        // 1. Try GET /client/v4/zones?name={zone}
        $response = CloudflareConnector::make($token)->send(GetZoneByNameRequest::make($zone));
        $data = $response->json();

        if ($response->successful() && Arr::get($data, 'success') === true) {
            $zoneId = Arr::get($data, 'result.0.id');
            if ($zoneId !== null) {
                return $zoneId;
            }
        }

        // 2. Fallback: Zone-scoped Cloudflare tokens often return an empty result []
        // on `GET /zones?name=...` with HTTP 200/success=true, but WILL return
        // the authorized zone in the unfiltered `GET /zones` list.
        $zones = $this->cloudflareListZones($token);
        foreach ($zones as $id => $name) {
            if (strcasecmp($name, $zone) === 0) {
                return (string) $id;
            }
        }

        // Diagnostic output for debugging token scope issues
        if (property_exists($this, 'output') && $this->output !== null && method_exists($this->output, 'isVerbose') && $this->output->isVerbose()) {
            $msg = Arr::get($data, 'errors.0.message') ?? $response->body();
            if (method_exists($this, 'laraKubeWarn')) {
                $this->laraKubeWarn("Cloudflare API response ({$response->status()}): {$msg}");
                $this->laraKubeWarn('Permitted zones for token: '.json_encode(array_values($zones)));
            }
        }

        return null;
    }

    /**
     * Every zone this token can see — unfiltered `GET /zones`, paginated.
     * A token's own Cloudflare-side scope IS the authoritative zone list; this
     * is what lets dns:init discover "which zones does this token cover"
     * instead of requiring the operator to retype a list that can drift out
     * of sync with the token's real scope.
     *
     * @return array<string, string> [zoneId => zoneName]
     */
    protected function cloudflareListZones(string $token): array
    {
        $connector = CloudflareConnector::make($token);
        $zones = [];
        $page = 1;

        do {
            $response = $connector->send(ListZonesRequest::make($page));
            $data = $response->json();

            if ($response->failed() || Arr::get($data, 'success') !== true) {
                return $zones;
            }

            foreach (Arr::get($data, 'result', []) as $zone) {
                if (isset($zone['id'], $zone['name'])) {
                    $zones[$zone['id']] = $zone['name'];
                }
            }

            $totalPages = (int) (Arr::get($data, 'result_info.total_pages') ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $zones;
    }

    /**
     * Read each managed zone's SSL mode — the /zones/{zoneId}/settings/ssl
     * leaf (the exact Cloudflare 9109 seam ChecksCloudflareProxy probes at
     * proxy-verify time). A token that can write DNS but not read this leaf
     * is still fully functional for what larakube stores it for — this probe
     * is the read-only, non-blocking informer (tls:show surfaces it live,
     * on demand), never a gate that refuses to store.
     *
     * @param  array<string, string>  $zones  [zoneId => zoneName] from cloudflareListZones()
     * @return array<string, string|null> [zoneName => mode] — 'strict'|'full'|'off'|'flexible', or null when the token can't read the leaf (needs Zone → Zone Settings → Read / Cloudflare 9109)
     */
    protected function cloudflareReadZoneSslModes(string $token, array $zones): array
    {
        $modes = [];

        foreach ($zones as $zoneId => $zoneName) {
            try {
                $response = CloudflareConnector::make($token)->send(GetZoneSettingRequest::make((string) $zoneId, 'ssl'));
                $modes[(string) $zoneName] = $response->successful() ? (string) Arr::get($response->json(), 'result.value') : null;
            } catch (Throwable) {
                $modes[(string) $zoneName] = null;
            }
        }

        return $modes;
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
        $connector = CloudflareConnector::make($token);

        $search = $connector->send(ListDnsRecordsRequest::make($zoneId, 'TXT', $name));
        $existingId = ($search->successful() && Arr::get($search->json(), 'success') === true)
            ? Arr::get($search->json(), 'result.0.id')
            : null;

        if ($existingId !== null) {
            $update = $connector->send(PatchDnsRecordRequest::make($zoneId, $existingId, $content, $ttl));

            return $update->successful() && Arr::get($update->json(), 'success') === true;
        }

        $create = $connector->send(CreateDnsRecordRequest::make($zoneId, 'TXT', $name, $content, $ttl));

        return $create->successful() && Arr::get($create->json(), 'success') === true;
    }

    /**
     * Create or update a CNAME record named $name on $zoneId pointing to $target.
     * Idempotent by $name: matches existing CNAME with the same name and updates target/TTL.
     */
    protected function cloudflareUpsertCnameRecord(string $zoneId, string $token, string $name, string $target, int $ttl = 120, bool $proxied = false): bool
    {
        $connector = CloudflareConnector::make($token);

        $search = $connector->send(ListDnsRecordsRequest::make($zoneId, 'CNAME', $name));
        $existingId = ($search->successful() && Arr::get($search->json(), 'success') === true)
            ? Arr::get($search->json(), 'result.0.id')
            : null;

        if ($existingId !== null) {
            $update = $connector->send(PatchDnsRecordRequest::make($zoneId, $existingId, $target, $ttl));

            return $update->successful() && Arr::get($update->json(), 'success') === true;
        }

        $create = $connector->send(CreateDnsRecordRequest::make($zoneId, 'CNAME', $name, $target, $ttl));

        return $create->successful() && Arr::get($create->json(), 'success') === true;
    }

    /**
     * Create or update an MX record named $name on $zoneId pointing to $target with $priority.
     */
    protected function cloudflareUpsertMxRecord(string $zoneId, string $token, string $name, string $target, int $priority = 10, int $ttl = 120): bool
    {
        $connector = CloudflareConnector::make($token);

        $search = $connector->send(ListDnsRecordsRequest::make($zoneId, 'MX', $name));
        $existingId = ($search->successful() && Arr::get($search->json(), 'success') === true)
            ? Arr::get($search->json(), 'result.0.id')
            : null;

        if ($existingId !== null) {
            $update = $connector->send(PatchDnsRecordRequest::make($zoneId, $existingId, $target, $ttl));

            return $update->successful() && Arr::get($update->json(), 'success') === true;
        }

        $create = $connector->send(CreateDnsRecordRequest::make($zoneId, 'MX', $name, $target, $ttl));

        return $create->successful() && Arr::get($create->json(), 'success') === true;
    }
}
