<?php

use App\Enums\CloudProvider;
use App\Http\Integrations\DigitalOcean\Requests\ListRegionsRequest;
use App\Http\Integrations\DigitalOcean\Requests\ListSizesRequest;
use App\Services\Cloud\LiveProviderCatalog;
use Illuminate\Support\Facades\File;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

$liveCatalogOriginalHome = $_SERVER['HOME'] ?? getenv('HOME');

afterEach(function () use ($liveCatalogOriginalHome): void {
    $_SERVER['HOME'] = $liveCatalogOriginalHome;
    putenv('HOME='.$liveCatalogOriginalHome);
});

function liveCatalogHome(): string
{
    $home = storage_path('framework/testing/live-catalog-'.bin2hex(random_bytes(5)));
    File::ensureDirectoryExists($home);
    $_SERVER['HOME'] = $home;
    putenv("HOME={$home}");

    return $home;
}

function doSizes(): array
{
    $size = fn (string $slug, int $mb, int $vcpus, float $price, bool $available = true): array => ['slug' => $slug, 'memory' => $mb, 'vcpus' => $vcpus, 'price_monthly' => $price, 'available' => $available];

    return ['sizes' => [
        $size('s-2vcpu-4gb', 4096, 2, 24),
        $size('s-1vcpu-1gb', 1024, 1, 6),
        $size('s-1vcpu-1gb-amd', 1024, 1, 7),
        $size('c-2', 4096, 2, 40),
        $size('s-8vcpu-32gb', 32768, 8, 192),
        $size('s-1vcpu-2gb', 2048, 1, 12, available: false),
    ]];
}

function doRegions(): array
{
    return ['regions' => [['slug' => 'nyc1', 'name' => 'New York 1', 'available' => true], ['slug' => 'old1', 'name' => 'Old', 'available' => false]]];
}

test('DigitalOcean sizes are the available basic Droplets, cheapest first, with the price the API gives', function (): void {
    $parsed = LiveProviderCatalog::parseDigitalOcean(doSizes(), doRegions());

    expect(array_column($parsed['sizes'], 'value'))->toBe(['s-1vcpu-1gb', 's-2vcpu-4gb'])
        ->and($parsed['sizes'][0]['label'])->toBe('s-1vcpu-1gb   —  1 vCPU,  1 GB RAM  (~$6/mo)')
        ->and($parsed['sizes'][0]['currency'])->toBe('USD')
        ->and($parsed['regions'])->toBe([['value' => 'nyc1', 'label' => 'nyc1 — New York 1']]);
});

test('a price change at the provider shows up in the label', function (): void {
    $sizes = doSizes();
    $sizes['sizes'][1]['price_monthly'] = 6.5;

    expect(LiveProviderCatalog::parseDigitalOcean($sizes, doRegions())['sizes'][0]['label'])->toContain('(~$6.5/mo)');
});

test('Hetzner types skip deprecated ones, price at the cheapest location, and say ARM or Dedicated', function (): void {
    $type = fn (string $name, int $cores, int $memory, array $prices, array $extra = []): array => $extra + ['name' => $name, 'cores' => $cores, 'memory' => $memory, 'architecture' => 'x86', 'cpu_type' => 'shared', 'deprecation' => null, 'prices' => array_map(fn (string $net): array => ['price_monthly' => ['net' => $net, 'gross' => $net]], $prices)];

    $parsed = LiveProviderCatalog::parseHetzner(['server_types' => [
        $type('cx33', 4, 8, ['6.99', '7.49']),
        $type('cax21', 4, 8, ['6.49'], ['architecture' => 'arm']),
        $type('cx22', 2, 4, ['3.79'], ['deprecation' => ['announced' => '2026-01-01']]),
        $type('ccx13', 2, 8, ['12.99'], ['cpu_type' => 'dedicated']),
        $type('cx11', 1, 1, ['3.00']),
    ]], ['locations' => [['name' => 'fsn1', 'city' => 'Falkenstein', 'country' => 'DE']]]);

    expect(array_column($parsed['sizes'], 'value'))->toBe(['cax21', 'cx33', 'ccx13'])
        ->and($parsed['sizes'][0]['label'])->toBe('cax21   —  4 vCPU (ARM), 8 GB RAM  (~€6.49/mo)')
        ->and($parsed['sizes'][2]['label'])->toContain('(Dedicated)')
        ->and($parsed['currency'])->toBe('EUR')
        ->and($parsed['regions'][0]['label'])->toBe('fsn1 — Falkenstein, DE');
});

test('the live answer is kept, so a second look does not ask the provider again', function (): void {
    liveCatalogHome();
    Saloon::fake([
        ListSizesRequest::class => MockResponse::make(doSizes()),
        ListRegionsRequest::class => MockResponse::make(doRegions()),
    ]);

    $catalog = new LiveProviderCatalog;
    $first = $catalog->get(CloudProvider::DO, 'token');
    $second = $catalog->get(CloudProvider::DO, 'token');

    expect($first['source'])->toBe('live')->and($second['source'])->toBe('live');
    Saloon::assertSentCount(2);
});

test('an old answer is used, and marked cached, when the provider cannot be reached', function (): void {
    $home = liveCatalogHome();
    $file = "{$home}/.larakube/cache/cloud-catalog-do.json";
    File::ensureDirectoryExists(dirname($file));
    File::put($file, json_encode(['fetchedAt' => time() - 86400, 'data' => LiveProviderCatalog::parseDigitalOcean(doSizes(), doRegions())]));
    Saloon::fake([ListSizesRequest::class => MockResponse::make([], 500), ListRegionsRequest::class => MockResponse::make([], 500)]);

    $answer = (new LiveProviderCatalog)->get(CloudProvider::DO, 'token');

    expect($answer['source'])->toBe('cached')->and($answer['sizes'][0]['value'])->toBe('s-1vcpu-1gb');
});

test('with no token, no connected provider, or nothing to fall back on, there is no live answer', function (): void {
    liveCatalogHome();
    Saloon::fake([ListSizesRequest::class => MockResponse::make([], 500), ListRegionsRequest::class => MockResponse::make([], 500)]);
    $catalog = new LiveProviderCatalog;

    expect($catalog->get(CloudProvider::DO, null))->toBeNull()
        ->and($catalog->get(CloudProvider::GCP, 'token'))->toBeNull()
        ->and($catalog->get(CloudProvider::DO, 'token'))->toBeNull();
});
