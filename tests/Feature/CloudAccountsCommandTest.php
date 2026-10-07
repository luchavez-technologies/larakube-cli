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
