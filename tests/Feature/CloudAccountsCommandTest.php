<?php

use App\Data\GlobalConfigData;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $config = GlobalConfigData::load();
    $config->addCloudAccount('do', 'Work Team', 'token-do-work', asDefault: true);
    $config->addCloudAccount('do', 'Client ACME', 'token-do-client', asDefault: false);
    $config->setHetznerToken('token-hetzner-default');
    $config->save();
});

test('cloud:accounts --json lists all accounts across providers', function (): void {
    Artisan::call('cloud:accounts', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output)->toBeArray()
        ->and($output['success'])->toBeTrue()
        ->and($output['accounts'])->toHaveKey('do')
        ->and($output['accounts'])->toHaveKey('hetzner')
        ->and($output['accounts']['do'])->toHaveCount(2);
});

test('cloud:accounts can add a named account for digitalocean', function (): void {
    Artisan::call('cloud:accounts', [
        '--provider' => 'do',
        '--name' => 'Agency Prod',
        '--token' => 'token-do-agency',
        '--add' => true,
        '--json' => true,
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['success'])->toBeTrue()
        ->and(GlobalConfigData::load()->getCloudAccounts('do'))->toHaveCount(3);
});

test('cloud:accounts can set default account', function (): void {
    $accounts = GlobalConfigData::load()->getCloudAccounts('do');
    $secondId = $accounts[1]['id'];

    Artisan::call('cloud:accounts', [
        '--provider' => 'do',
        '--set-default' => $secondId,
        '--json' => true,
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['success'])->toBeTrue()
        ->and(GlobalConfigData::load()->getDefaultCloudAccount('do')['id'])->toBe($secondId)
        ->and(GlobalConfigData::load()->getDoToken())->toBe('token-do-client');
});

test('cloud:accounts can remove an account', function (): void {
    $accounts = GlobalConfigData::load()->getCloudAccounts('do');
    $secondId = $accounts[1]['id'];

    Artisan::call('cloud:accounts', [
        '--provider' => 'do',
        '--remove' => $secondId,
        '--json' => true,
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['success'])->toBeTrue()
        ->and(GlobalConfigData::load()->getCloudAccounts('do'))->toHaveCount(1);
});

test('cloud:accounts can remove an AWS profile', function (): void {
    App\Services\Cloud\AwsCredentialsFile::save(home_path(), 'KEY', 'SEC', 'us-east-1', 'client-temp');

    Artisan::call('cloud:accounts', [
        '--provider' => 'aws',
        '--remove' => 'client-temp',
        '--json' => true,
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['success'])->toBeTrue()
        ->and($output['removed'])->toBe('client-temp');
});

test('cloud:accounts can add a gcp service account and list it', function (): void {
    $sa = [
        'type' => 'service_account',
        'project_id' => 'sa-project-123',
        'client_email' => 'sa-dev@sa-project-123.iam.gserviceaccount.com',
    ];
    $filePath = home_path().'/test-sa.json';
    file_put_contents($filePath, json_encode($sa));

    Artisan::call('cloud:accounts', [
        '--provider' => 'gcp',
        '--name' => $filePath,
        '--add' => true,
        '--json' => true,
    ]);

    $output = json_decode(Artisan::output(), true);
    expect($output['success'])->toBeTrue()
        ->and($output['provider'])->toBe('gcp')
        ->and($output['account'])->toBe('sa-dev@sa-project-123.iam.gserviceaccount.com');

    Artisan::call('cloud:accounts', ['--provider' => 'gcp', '--json' => true]);
    $listOutput = json_decode(Artisan::output(), true);
    $gcpAccounts = $listOutput['accounts']['gcp'] ?? [];

    expect(collect($gcpAccounts)->contains(fn ($acc) => $acc['id'] === 'sa-dev@sa-project-123.iam.gserviceaccount.com'))->toBeTrue();

    @unlink($filePath);
});
