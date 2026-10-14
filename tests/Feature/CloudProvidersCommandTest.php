<?php

use App\Data\GlobalConfigData;
use App\Enums\CloudProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * Credential env vars the command consults — cleared so the host's own
 * shell environment can't leak into the result.
 */
function cloudProvidersClearCredentialEnv(): void
{
    foreach (['TF_VAR_do_token', 'HCLOUD_TOKEN', 'HETZNER_TOKEN', 'GOOGLE_PROJECT', 'CLOUDSDK_CORE_PROJECT', 'GCP_PROJECT', 'GOOGLE_APPLICATION_CREDENTIALS', 'GOOGLE_CREDENTIALS', 'AWS_PROFILE', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'] as $key) {
        putenv($key);
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function cloudProvidersRunJson(): array
{
    expect(Artisan::call('cloud:providers', ['--json' => true]))->toBe(0);

    $decoded = json_decode(trim(Artisan::output()), true);

    expect($decoded['success'])->toBeTrue();

    return collect($decoded['providers'])->keyBy('slug')->all();
}

test('--json lists every active provider with its regions, sizes, and defaults', function (): void {
    cloudProvidersClearCredentialEnv();
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $providers = cloudProvidersRunJson();

    expect(array_keys($providers))->toBe(array_keys(CloudProvider::activeProviders()));

    $gcp = $providers['gcp'];
    expect($gcp['label'])->toBe(CloudProvider::GCP->label())
        ->and($gcp['defaultRegion'])->toBe(CloudProvider::GCP->defaultRegion())
        ->and($gcp['defaultVpsSize'])->toBe(CloudProvider::GCP->defaultVpsSize())
        ->and(array_column($gcp['regions'], 'value'))->toBe(array_keys(CloudProvider::GCP->regions()))
        ->and(array_column($gcp['vpsSizes'], 'value'))->toBe(array_keys(CloudProvider::GCP->vpsSizes()));
});

test('--json exposes each provider\'s managed-Kubernetes mapping, HA model, and HA cost', function (): void {
    cloudProvidersClearCredentialEnv();
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $providers = cloudProvidersRunJson();

    expect($providers['do'])->toMatchArray(['managedProvider' => 'doks', 'haOption' => 'boolean', 'haCost' => '$40/month'])
        // GKE's "HA" is the zonal-vs-regional location choice — a real toggle,
        // not always-on (EKS's control plane really is always multi-AZ, no
        // non-HA option exists on that provider at all).
        ->and($providers['gcp'])->toMatchArray(['managedProvider' => 'gke', 'haOption' => 'boolean', 'haCost' => '~$73/month (regional control plane)'])
        // 'always' means no non-HA option exists, never "no extra cost" —
        // EKS's flat control-plane fee applies regardless.
        ->and($providers['aws'])->toMatchArray(['managedProvider' => 'eks', 'haOption' => 'always', 'haCost' => '~$73/month'])
        ->and($providers['hetzner'])->toMatchArray(['managedProvider' => 'custom', 'haOption' => 'unknown', 'haCost' => null])
        ->and($providers['hetzner']['managedSizes'])->toBe([]);
});

test('a saved DigitalOcean token reports ready, a missing one reports a hint', function (): void {
    cloudProvidersClearCredentialEnv();
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(cloudProvidersRunJson()['do']['credentials'])
        ->toBe(['ready' => false, 'hint' => 'No DigitalOcean API token saved.']);

    $config = GlobalConfigData::load();
    $config->setDoToken('dop_v1_test');
    $config->save();

    expect(cloudProvidersRunJson()['do']['credentials'])
        ->toBe(['ready' => true, 'hint' => null]);
});

test('gcloud logged in with a project selected reports ready', function (): void {
    cloudProvidersClearCredentialEnv();
    putenv('GOOGLE_PROJECT=demo-project');
    Process::fake([
        'command -v gcloud' => Process::result(output: '/usr/local/bin/gcloud'),
        '*auth print-access-token*' => Process::result(output: 'ya29.token'),
        '*' => Process::result(exitCode: 1),
    ]);

    try {
        expect(cloudProvidersRunJson()['gcp']['credentials'])->toBe(['ready' => true, 'hint' => null]);
    } finally {
        putenv('GOOGLE_PROJECT');
    }
});

test('missing GCP project and missing AWS CLI each report a hint', function (): void {
    cloudProvidersClearCredentialEnv();
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $providers = cloudProvidersRunJson();

    expect($providers['gcp']['credentials'])->toBe(['ready' => false, 'hint' => 'No Google Cloud project selected.'])
        ->and($providers['aws']['credentials'])->toBe(['ready' => false, 'hint' => 'AWS CLI is not installed.']);
});

test('every provider names a dev box size that is one of its sizes and has at least 8 GB of RAM', function (): void {
    cloudProvidersClearCredentialEnv();
    Process::fake();

    foreach (cloudProvidersRunJson() as $slug => $provider) {
        $size = collect($provider['vpsSizes'])->firstWhere('value', $provider['defaultDevBoxSize']);

        expect($size)->not->toBeNull("{$slug} lists no size {$provider['defaultDevBoxSize']}");
        preg_match('/(\d+(?:\.\d+)?) GB RAM/', $size['label'], $match);
        expect((float) ($match[1] ?? 0))->toBeGreaterThanOrEqual(8.0);
    }
});

test('providers include accounts and activeAccount in json output', function (): void {
    cloudProvidersClearCredentialEnv();
    $config = GlobalConfigData::load();
    $config->addCloudAccount('do', 'Work Token', 'token-do-work', asDefault: true);
    $config->addCloudAccount('do', 'Personal Token', 'token-do-pers', asDefault: false);
    $config->save();

    Process::fake();

    $providers = cloudProvidersRunJson();
    expect($providers['do'])->toHaveKey('accounts')
        ->and($providers['do']['accounts'])->toHaveCount(2)
        ->and($providers['do']['activeAccount'])->not->toBeNull();
});

test('AWS and GCP always mark their price as an estimate with a real pricing-page link; DO/Hetzner don\'t', function (): void {
    cloudProvidersClearCredentialEnv();
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $providers = cloudProvidersRunJson();

    expect($providers['aws']['pricing'])->toBe(['source' => 'builtin', 'asOf' => null, 'currency' => null, 'priceIsEstimated' => true, 'officialPricingUrl' => CloudProvider::AWS->pricingUrl()])
        ->and($providers['gcp']['pricing']['priceIsEstimated'])->toBeTrue()
        ->and($providers['gcp']['pricing']['officialPricingUrl'])->toBe(CloudProvider::GCP->pricingUrl())
        ->and($providers['do']['pricing'])->toBe(['source' => 'builtin', 'asOf' => null, 'currency' => null, 'priceIsEstimated' => false, 'officialPricingUrl' => null])
        ->and($providers['hetzner']['pricing']['priceIsEstimated'])->toBeFalse();
});

test('a connected AWS account gets its regions/sizes confirmed live, including the Managed Kubernetes list', function (): void {
    cloudProvidersClearCredentialEnv();
    Process::fake([
        'command -v aws' => Process::result(output: '/usr/local/bin/aws'),
        '*aws sts get-caller-identity*' => Process::result(output: '{"Account":"123"}'),
        '*ec2 describe-regions*' => Process::result(output: (string) json_encode(['Regions' => [['RegionName' => 'us-east-1']]])),
        '*ec2 describe-instance-types*' => Process::result(output: (string) json_encode(['InstanceTypes' => [
            ['InstanceType' => 't3.medium', 'BareMetal' => false, 'VCpuInfo' => ['DefaultVCpus' => 2], 'MemoryInfo' => ['SizeInMiB' => 4096]],
            ['InstanceType' => 'p5.48xlarge', 'BareMetal' => false, 'VCpuInfo' => ['DefaultVCpus' => 192], 'MemoryInfo' => ['SizeInMiB' => 2097152]], // GPU family — must not appear
        ]])),
        '*' => Process::result(exitCode: 1),
    ]);

    $providers = cloudProvidersRunJson();

    expect($providers['aws']['credentials']['ready'])->toBeTrue()
        ->and($providers['aws']['pricing']['source'])->toBe('live')
        ->and(array_column($providers['aws']['regions'], 'value'))->toBe(['us-east-1'])
        ->and(array_column($providers['aws']['vpsSizes'], 'value'))->toBe(['t3.medium'])
        // Managed Kubernetes gets the exact same open, live-confirmed list
        // as the VPS picker now — not a second, narrower curated subset.
        ->and(array_column($providers['aws']['managedSizes'], 'value'))->toBe(['t3.medium']);
});

test('AWS provider surfaces specific STS error code when authentication fails', function (): void {
    cloudProvidersClearCredentialEnv();
    Process::fake([
        'command -v aws' => Process::result(output: '/usr/local/bin/aws'),
        '*aws sts get-caller-identity*' => Process::result(
            "An error occurred (InvalidClientTokenId) when calling the GetCallerIdentity operation: The security token included in the request is invalid.\n",
            exitCode: 254,
        ),
        '*' => Process::result(exitCode: 1),
    ]);

    $providers = cloudProvidersRunJson();
    expect($providers['aws']['credentials'])->toBe([
        'ready' => false,
        'hint' => 'AWS authentication failed (InvalidClientTokenId).',
    ]);
});

test('--status-only --json returns only provider metadata and credentials without live catalog discovery', function (): void {
    cloudProvidersClearCredentialEnv();
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(Artisan::call('cloud:providers', ['--json' => true, '--status-only' => true]))->toBe(0);

    $decoded = json_decode(trim(Artisan::output()), true);
    expect($decoded['success'])->toBeTrue();

    $providers = collect($decoded['providers'])->keyBy('slug')->all();
    expect(array_keys($providers))->toBe(array_keys(CloudProvider::activeProviders()));

    $do = $providers['do'];
    expect($do)->toHaveKeys(['slug', 'label', 'regions', 'defaultRegion', 'credentials', 'accounts', 'activeAccount'])
        ->and($do)->not->toHaveKeys(['vpsSizes', 'managedSizes', 'pricing', 'defaultDevBoxSize']);
});

test('--provider option filters to a single provider', function (): void {
    cloudProvidersClearCredentialEnv();
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(Artisan::call('cloud:providers', ['--json' => true, '--status-only' => true, '--provider' => 'aws']))->toBe(0);

    $decoded = json_decode(trim(Artisan::output()), true);
    expect($decoded['success'])->toBeTrue()
        ->and($decoded['providers'])->toHaveCount(1)
        ->and($decoded['providers'][0]['slug'])->toBe('aws');
});

test('--provider with invalid name returns an error exit code', function (): void {
    expect(Artisan::call('cloud:providers', ['--provider' => 'invalid-cloud']))->toBe(1);
});
