<?php

use App\Enums\CloudProvider;
use App\Http\Integrations\DigitalOcean\Requests\ListRegionsRequest;
use App\Http\Integrations\DigitalOcean\Requests\ListSizesRequest;
use App\Services\Cloud\LiveProviderCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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

function awsInstanceType(string $code, int $vcpu, int $memoryMib, bool $bareMetal = false): array
{
    return ['InstanceType' => $code, 'BareMetal' => $bareMetal, 'VCpuInfo' => ['DefaultVCpus' => $vcpu], 'MemoryInfo' => ['SizeInMiB' => $memoryMib]];
}

test('AWS sizes are a genuinely open discovery, not a curated handful — real vCPU/RAM, general-purpose families only', function (): void {
    $regionsJson = ['Regions' => [
        ['RegionName' => 'us-east-1'],
        ['RegionName' => 'ap-south-2'], // not in the static friendly-name map
    ]];
    $typesJson = ['InstanceTypes' => [
        awsInstanceType('t3.medium', 2, 4096),
        awsInstanceType('t3.micro', 2, 1024),
        awsInstanceType('m7a.48xlarge', 192, 786432), // big, but still a general-purpose family — must appear
        awsInstanceType('p5.48xlarge', 192, 2097152), // GPU family — must NOT appear
        awsInstanceType('m5.metal', 96, 393216, bareMetal: true), // bare metal — must NOT appear
    ]];

    $parsed = LiveProviderCatalog::parseAws($regionsJson, $typesJson);

    expect(array_column($parsed['sizes'], 'value'))->toBe(['t3.micro', 't3.medium', 'm7a.48xlarge'])
        ->and($parsed['sizes'][0]['label'])->toBe('t3.micro   —  2 vCPU, 1 GB RAM')
        ->and($parsed['currency'])->toBe('USD')
        ->and($parsed['regions'])->toContain(['value' => 'us-east-1', 'label' => CloudProvider::AWS->regions()['us-east-1']])
        ->and($parsed['regions'])->toContain(['value' => 'ap-south-2', 'label' => 'ap-south-2']);
});

test('GCP sizes are the same open discovery from gcloud\'s bare-array JSON, same as AWS', function (): void {
    $regionsJson = [
        ['name' => 'us-central1'],
        ['name' => 'africa-south1'], // not in the static friendly-name map
    ];
    $typesJson = [
        ['name' => 'e2-medium', 'guestCpus' => 2, 'memoryMb' => 4096],
        ['name' => 'n2-standard-8', 'guestCpus' => 8, 'memoryMb' => 32768],
        ['name' => 'a2-highgpu-1g', 'guestCpus' => 12, 'memoryMb' => 87040], // GPU family — must NOT appear
    ];

    $parsed = LiveProviderCatalog::parseGcp($regionsJson, $typesJson);

    expect(array_column($parsed['sizes'], 'value'))->toBe(['e2-medium', 'n2-standard-8'])
        ->and($parsed['sizes'][0]['label'])->toBe('e2-medium   —  2 vCPU, 4 GB RAM')
        ->and($parsed['regions'])->toContain(['value' => 'us-central1', 'label' => CloudProvider::GCP->regions()['us-central1']])
        ->and($parsed['regions'])->toContain(['value' => 'africa-south1', 'label' => 'africa-south1']);
});

test('parseAws/parseGcp return null when nothing was confirmed live', function (): void {
    expect(LiveProviderCatalog::parseAws(['Regions' => []], ['InstanceTypes' => []]))->toBeNull()
        ->and(LiveProviderCatalog::parseAws(['Regions' => [['RegionName' => 'us-east-1']]], ['InstanceTypes' => []]))->toBeNull()
        ->and(LiveProviderCatalog::parseGcp([], []))->toBeNull();
});

test('get() shells out to aws/gcloud for AWS and GCP, trusting an empty AWS env (credentials can live entirely in ~/.aws/credentials)', function (): void {
    liveCatalogHome();
    Process::fake([
        '*ec2 describe-regions*' => Process::result(output: (string) json_encode(['Regions' => [['RegionName' => 'us-east-1']]])),
        '*ec2 describe-instance-types*' => Process::result(output: (string) json_encode(['InstanceTypes' => [awsInstanceType('t3.medium', 2, 4096)]])),
        '*compute regions list*' => Process::result(output: (string) json_encode([['name' => 'us-central1']])),
        '*compute machine-types list*' => Process::result(output: (string) json_encode([['name' => 'e2-medium', 'guestCpus' => 2, 'memoryMb' => 4096]])),
    ]);

    $catalog = new LiveProviderCatalog;
    $aws = $catalog->get(CloudProvider::AWS, []);
    $gcp = $catalog->get(CloudProvider::GCP, 'demo-project');

    expect($aws['source'])->toBe('live')
        ->and(array_column($aws['sizes'], 'value'))->toBe(['t3.medium'])
        ->and($gcp['source'])->toBe('live')
        ->and(array_column($gcp['sizes'], 'value'))->toBe(['e2-medium']);
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

test('with no credential, or nothing to fall back on, there is no live answer', function (): void {
    liveCatalogHome();
    Saloon::fake([ListSizesRequest::class => MockResponse::make([], 500), ListRegionsRequest::class => MockResponse::make([], 500)]);
    $catalog = new LiveProviderCatalog;

    expect($catalog->get(CloudProvider::DO, null))->toBeNull()
        ->and($catalog->get(CloudProvider::GCP, ''))->toBeNull()
        ->and($catalog->get(CloudProvider::GCP, null))->toBeNull()
        ->and($catalog->get(CloudProvider::DO, 'token'))->toBeNull();
});
