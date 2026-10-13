<?php

namespace App\Services\Cloud;

use App\Enums\CliTool;
use App\Enums\CloudProvider;
use App\Http\Integrations\DigitalOcean\DigitalOceanConnector;
use App\Http\Integrations\DigitalOcean\Requests\ListRegionsRequest;
use App\Http\Integrations\DigitalOcean\Requests\ListSizesRequest;
use App\Http\Integrations\Hetzner\HetznerConnector;
use App\Http\Integrations\Hetzner\Requests\ListLocationsRequest;
use App\Http\Integrations\Hetzner\Requests\ListServerTypesRequest;
use Illuminate\Support\Facades\Process;
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
     * $credential is a provider token (DO/Hetzner), a GCP project ID, or the
     * `aws` CLI env vars from InteractsWithAws::buildAwsEnv() — that last one
     * can legitimately be an empty array (credentials living entirely in
     * `~/.aws/credentials`), so AWS trusts the caller's own readiness check
     * instead of this method's own non-empty-string gate.
     *
     * @return array{regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, monthly: float, currency: string}>, currency: string, source: string, asOf: string}|null
     */
    public function get(CloudProvider $provider, mixed $credential, bool $refresh = false): ?array
    {
        if (! in_array($provider, [CloudProvider::DO, CloudProvider::HETZNER, CloudProvider::AWS, CloudProvider::GCP], true)) {
            return null;
        }

        if ($provider !== CloudProvider::AWS && (! is_string($credential) || $credential === '')) {
            return null;
        }

        if ($provider === CloudProvider::AWS && ! is_array($credential)) {
            return null;
        }

        $cached = $this->read($provider);

        if ($cached !== null && ! $refresh && time() - $cached['fetchedAt'] < self::FRESH_SECONDS) {
            return $this->present($cached, 'live');
        }

        $fetched = $this->fetch($provider, $credential);

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
     * General-purpose/compute/memory-optimized ("t"/"m"/"c"/"r" family)
     * instance types only — not a curated handful, every current-generation,
     * non-bare-metal one AWS actually offers this account. GPU/FPGA/HPC/
     * storage-dense families are deliberately left out: they need device
     * plugins and specialized AMIs LaraKube doesn't wire up, so listing them
     * as a plain node choice would be misleading, not more complete.
     * `describe-instance-types` carries no price at all — `monthly` here is
     * a vCPU+RAM sort key, never a dollar figure; the caller's own
     * "prices are estimates" note is what covers price.
     *
     * @param  array<string, mixed>  $regionsJson
     * @param  array<string, mixed>  $typesJson
     * @return array{regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, monthly: float, currency: string}>, currency: string}|null
     */
    public static function parseAws(array $regionsJson, array $typesJson): ?array
    {
        $regionLabels = CloudProvider::AWS->regions();
        $places = [];

        foreach ((array) ($regionsJson['Regions'] ?? []) as $region) {
            if (is_array($region) && is_string($region['RegionName'] ?? null)) {
                $code = $region['RegionName'];
                $places[] = ['value' => $code, 'label' => $regionLabels[$code] ?? $code];
            }
        }

        $options = [];

        foreach ((array) ($typesJson['InstanceTypes'] ?? []) as $type) {
            if (! is_array($type) || ! is_string($type['InstanceType'] ?? null) || preg_match('/^[tmcr]\d/', $type['InstanceType']) !== 1) {
                continue;
            }

            // Defense-in-depth: fetchAws()'s own --filters already excludes
            // bare metal server-side, but a pure function shouldn't trust its
            // caller alone for something this consequential to filter out.
            if (($type['BareMetal'] ?? false) === true) {
                continue;
            }

            $vcpu = (int) ($type['VCpuInfo']['DefaultVCpus'] ?? 0);
            $memory = (int) round(((float) ($type['MemoryInfo']['SizeInMiB'] ?? 0)) / 1024);

            if ($vcpu <= 0 || $memory <= 0) {
                continue;
            }

            $options[] = self::sizeOption($type['InstanceType'], $vcpu, $memory, 'USD');
        }

        usort($options, fn (array $a, array $b): int => $a['monthly'] <=> $b['monthly']);

        return $options === [] || $places === [] ? null : ['regions' => $places, 'sizes' => $options, 'currency' => 'USD'];
    }

    /**
     * Same filtering/shape as parseAws(), for GCP's general-purpose/compute/
     * memory-optimized machine families — `gcloud ... --format=json` returns
     * a bare array rather than a keyed object, and `compute machine-types
     * list` carries no price either.
     *
     * @param  list<mixed>  $regionsJson
     * @param  list<mixed>  $typesJson
     * @return array{regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, monthly: float, currency: string}>, currency: string}|null
     */
    public static function parseGcp(array $regionsJson, array $typesJson): ?array
    {
        $regionLabels = CloudProvider::GCP->regions();
        $places = [];

        foreach ($regionsJson as $region) {
            if (is_array($region) && is_string($region['name'] ?? null)) {
                $code = $region['name'];
                $places[] = ['value' => $code, 'label' => $regionLabels[$code] ?? $code];
            }
        }

        $allowedFamilies = ['e2', 'n1', 'n2', 'n2d', 'n4', 'n4a', 'n4d', 'c2', 'c2d', 'c3', 'c3d', 'c4', 'c4a', 'c4d', 'c4n', 'm1', 'm2', 'm3', 'm4', 'm4n', 't2a', 't2d'];
        $options = [];

        foreach ($typesJson as $type) {
            if (! is_array($type) || ! is_string($type['name'] ?? null)) {
                continue;
            }

            $family = strstr($type['name'], '-', true);
            if ($family === false || ! in_array($family, $allowedFamilies, true)) {
                continue;
            }

            $vcpu = (int) ($type['guestCpus'] ?? 0);
            $memory = (int) round(((float) ($type['memoryMb'] ?? 0)) / 1024);

            if ($vcpu <= 0 || $memory <= 0) {
                continue;
            }

            $options[] = self::sizeOption($type['name'], $vcpu, $memory, 'USD');
        }

        usort($options, fn (array $a, array $b): int => $a['monthly'] <=> $b['monthly']);

        return $options === [] || $places === [] ? null : ['regions' => $places, 'sizes' => $options, 'currency' => 'USD'];
    }

    /**
     * @return array{value: string, label: string, monthly: float, currency: string}
     */
    private static function sizeOption(string $code, int $vcpu, int $memory, string $currency): array
    {
        return [
            'value' => $code,
            'label' => sprintf('%s   —  %d vCPU, %d GB RAM', $code, $vcpu, $memory),
            // No live price exists for AWS/GCP — this is a vCPU+RAM sort key
            // (smallest first), never a dollar figure.
            'monthly' => (float) ($vcpu * 1000 + $memory),
            'currency' => $currency,
        ];
    }

    /**
     * @return array{regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, monthly: float, currency: string}>, currency: string}|null
     */
    private function fetch(CloudProvider $provider, mixed $credential): ?array
    {
        try {
            return match ($provider) {
                CloudProvider::DO => $this->fetchDigitalOcean((string) $credential),
                CloudProvider::HETZNER => $this->fetchHetzner((string) $credential),
                CloudProvider::AWS => $this->fetchAws((array) $credential),
                CloudProvider::GCP => $this->fetchGcp((string) $credential),
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

    /**
     * `--filters` pushes the cheap part of the narrowing (current-gen,
     * non-bare-metal) to AWS itself; the family-prefix allowlist (t/m/c/r
     * only) happens client-side in parseAws() since it's a lot easier to
     * read/test as PHP than as a growing wildcard filter string that would
     * need updating every time AWS ships a new generation letter.
     *
     * @param  array<string, string>  $env
     */
    private function fetchAws(array $env): ?array
    {
        $bin = CliTool::AWS->resolveBinary() ?? 'aws';

        $regions = Process::env($env)->timeout(10)->run("{$bin} ec2 describe-regions --output json");
        $types = Process::env($env)->timeout(15)->run("{$bin} ec2 describe-instance-types --output json --filters Name=current-generation,Values=true Name=bare-metal,Values=false");

        if (! $regions->successful() || ! $types->successful()) {
            return null;
        }

        return self::parseAws((array) json_decode($regions->output(), true), (array) json_decode($types->output(), true));
    }

    private function fetchGcp(string $projectId): ?array
    {
        $bin = CliTool::GCLOUD->resolveBinary() ?? 'gcloud';
        $zone = CloudProvider::GCP->defaultRegion().'-a';
        $env = ['CLOUDSDK_CORE_DISABLE_PROMPTS' => '1'];

        $regions = Process::env($env)->timeout(10)->run("{$bin} compute regions list --quiet --project=".escapeshellarg($projectId).' --format=json');
        $types = Process::env($env)->timeout(15)->run("{$bin} compute machine-types list --quiet --project=".escapeshellarg($projectId).' --filter='.escapeshellarg("zone:{$zone}").' --format=json');

        if (! $regions->successful() || ! $types->successful()) {
            return null;
        }

        return self::parseGcp((array) json_decode($regions->output(), true), (array) json_decode($types->output(), true));
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
