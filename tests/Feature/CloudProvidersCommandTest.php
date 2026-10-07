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
