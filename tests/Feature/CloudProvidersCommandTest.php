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
