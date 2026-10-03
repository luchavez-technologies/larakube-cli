<?php

namespace App\Services\Cloud;

use App\Enums\CloudProvider;
use App\Http\Integrations\DigitalOcean\DigitalOceanConnector;
use App\Http\Integrations\DigitalOcean\Requests\ListRegionsRequest;
use App\Http\Integrations\DigitalOcean\Requests\ListSizesRequest;
use App\Http\Integrations\Hetzner\HetznerConnector;
use App\Http\Integrations\Hetzner\Requests\ListLocationsRequest;
use App\Http\Integrations\Hetzner\Requests\ListServerTypesRequest;
use Throwable;

/**
 * A provider's regions, sizes and monthly prices as the provider lists them
 * today, so a picker never shows a price that went stale in the source code.
 * Needs the provider's token, so it answers only once that provider is
 * connected; the caller falls back to the built-in list otherwise.
 *
 * Answers are kept on disk for a few hours, and an old answer is still better
 * than none when the provider cannot be reached (it is then marked `cached`).
 */
class LiveProviderCatalog
{
    private const FRESH_SECONDS = 6 * 3600;

    private const STALE_SECONDS = 14 * 86400;

    /**
     * @return array{regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, monthly: float, currency: string}>, currency: string, source: string, asOf: string}|null
     */
    public function get(CloudProvider $provider, ?string $token, bool $refresh = false): ?array
    {
        if (! in_array($provider, [CloudProvider::DO, CloudProvider::HETZNER], true) || $token === null || $token === '') {
            return null;
        }

        $cached = $this->read($provider);

        if ($cached !== null && ! $refresh && time() - $cached['fetchedAt'] < self::FRESH_SECONDS) {
            return $this->present($cached, 'live');
        }

        $fetched = $this->fetch($provider, $token);

        if ($fetched !== null) {
            $this->write($provider, $fetched);

            return $this->present(['fetchedAt' => time(), 'data' => $fetched], 'live');
        }

        if ($cached !== null && time() - $cached['fetchedAt'] < self::STALE_SECONDS) {
            return $this->present($cached, 'cached');
        }

        return null;
    }

    /**
     * Basic Droplets only (the shared-CPU `s-` sizes the servers use), cheapest first.
     *
     * @param  array<string, mixed>  $sizes
     * @param  array<string, mixed>  $regions
     * @return array{regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, monthly: float, currency: string}>, currency: string}|null
     */
    public static function parseDigitalOcean(array $sizes, array $regions): ?array
    {
        $options = [];

        foreach ((array) ($sizes['sizes'] ?? []) as $size) {
            if (! is_array($size) || ($size['available'] ?? false) !== true || ! preg_match('/^s-(\d+)vcpu-(\d+)gb$/', (string) ($size['slug'] ?? ''), $match)) {
                continue;
            }

            $memory = (int) round(((float) ($size['memory'] ?? 0)) / 1024);
            $price = (float) ($size['price_monthly'] ?? 0);

            if ($price <= 0 || $memory < 1 || $memory > 16) {
                continue;
            }

            $options[] = [
                'value' => (string) $size['slug'],
                'label' => sprintf('%s   —  %d vCPU,  %d GB RAM  (~%s/mo)', $size['slug'], (int) $match[1], $memory, self::money($price, '$')),
                'monthly' => $price,
                'currency' => 'USD',
            ];
        }

        usort($options, fn (array $a, array $b): int => $a['monthly'] <=> $b['monthly']);

        $places = [];

        foreach ((array) ($regions['regions'] ?? []) as $region) {
            if (is_array($region) && ($region['available'] ?? false) === true && isset($region['slug'], $region['name'])) {
                $places[] = ['value' => (string) $region['slug'], 'label' => "{$region['slug']} — {$region['name']}"];
            }
        }

        return $options === [] || $places === [] ? null : ['regions' => $places, 'sizes' => array_slice($options, 0, 8), 'currency' => 'USD'];
    }

    /**
     * Current (not deprecated) server types, priced at their cheapest location (excluding VAT).
     *
     * @param  array<string, mixed>  $types
     * @param  array<string, mixed>  $locations
     * @return array{regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, monthly: float, currency: string}>, currency: string}|null
     */
    public static function parseHetzner(array $types, array $locations): ?array
    {
        $options = [];

        foreach ((array) ($types['server_types'] ?? []) as $type) {
            if (! is_array($type) || ($type['deprecation'] ?? null) !== null || ! isset($type['name'])) {
                continue;
            }

            $memory = (int) round((float) ($type['memory'] ?? 0));
            $prices = array_filter(array_map(fn (mixed $row): ?float => is_array($row) && isset($row['price_monthly']['net']) ? (float) $row['price_monthly']['net'] : null, (array) ($type['prices'] ?? [])));

            if ($prices === [] || $memory < 2 || $memory > 32) {
                continue;
            }

            $price = min($prices);
            $kind = ($type['architecture'] ?? 'x86') === 'arm' ? ' (ARM)' : (($type['cpu_type'] ?? 'shared') === 'dedicated' ? ' (Dedicated)' : '');

            $options[] = [
                'value' => (string) $type['name'],
                'label' => sprintf('%s   —  %d vCPU%s, %d GB RAM  (~%s/mo)', $type['name'], (int) ($type['cores'] ?? 0), $kind, $memory, self::money($price, '€')),
                'monthly' => $price,
                'currency' => 'EUR',
            ];
        }

        usort($options, fn (array $a, array $b): int => $a['monthly'] <=> $b['monthly']);

        $places = [];

        foreach ((array) ($locations['locations'] ?? []) as $location) {
            if (is_array($location) && isset($location['name'])) {
                $places[] = ['value' => (string) $location['name'], 'label' => "{$location['name']} — ".trim(($location['city'] ?? '').', '.($location['country'] ?? ''), ', ')];
            }
        }

        return $options === [] || $places === [] ? null : ['regions' => $places, 'sizes' => array_slice($options, 0, 8), 'currency' => 'EUR'];
    }

    /**
     * @return array{regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, monthly: float, currency: string}>, currency: string}|null
     */
    private function fetch(CloudProvider $provider, string $token): ?array
    {
        try {
            return match ($provider) {
                CloudProvider::DO => $this->fetchDigitalOcean($token),
                CloudProvider::HETZNER => $this->fetchHetzner($token),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function fetchDigitalOcean(string $token): ?array
    {
        $connector = new DigitalOceanConnector($token);
        $sizes = $connector->send(new ListSizesRequest);
        $regions = $connector->send(new ListRegionsRequest);

        if (! $sizes->successful() || ! $regions->successful()) {
            return null;
        }

        return self::parseDigitalOcean($sizes->json(), $regions->json());
    }

    private function fetchHetzner(string $token): ?array
    {
        $connector = new HetznerConnector($token);
        $types = $connector->send(new ListServerTypesRequest);
        $locations = $connector->send(new ListLocationsRequest);

        if (! $types->successful() || ! $locations->successful()) {
            return null;
        }

        return self::parseHetzner($types->json(), $locations->json());
    }

    /** `6`, not `6.00`; `3.79` stays. */
    private static function money(float $amount, string $symbol): string
    {
        $text = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');

        return $symbol.$text;
    }

    /**
     * @param  array{fetchedAt: int, data: array<string, mixed>}  $cached
     * @return array<string, mixed>
     */
    private function present(array $cached, string $source): array
    {
        return $cached['data'] + ['source' => $source, 'asOf' => date('c', $cached['fetchedAt'])];
    }

    /**
     * @return array{fetchedAt: int, data: array<string, mixed>}|null
     */
    private function read(CloudProvider $provider): ?array
    {
        $file = $this->file($provider);

        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) && is_int($decoded['fetchedAt'] ?? null) && is_array($decoded['data'] ?? null) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(CloudProvider $provider, array $data): void
    {
        $file = $this->file($provider);

        @mkdir(dirname($file), 0755, true);
        @file_put_contents($file, json_encode(['fetchedAt' => time(), 'data' => $data]));
    }

    private function file(CloudProvider $provider): string
    {
        return home_path('.larakube/cache/cloud-catalog-'.$provider->value.'.json');
    }
}
