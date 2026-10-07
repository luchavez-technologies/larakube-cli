<?php

use App\Data\GlobalConfigData;

test('it auto-migrates legacy doToken into cloudAccounts', function (): void {
    $config = new GlobalConfigData(doToken: 'dop_v1_legacy_token');

    $accounts = $config->getCloudAccounts('do');

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]['token'])->toBe('dop_v1_legacy_token')
        ->and($accounts[0]['default'])->toBeTrue();
});

test('it can add, switch, and remove named cloud accounts', function (): void {
    $config = new GlobalConfigData;

    $id1 = $config->addCloudAccount('do', 'Personal', 'token-1', asDefault: true);
    $id2 = $config->addCloudAccount('do', 'Agency Client', 'token-2', asDefault: false);

    expect($config->getCloudAccounts('do'))->toHaveCount(2)
        ->and($config->getDefaultCloudAccount('do')['id'])->toBe($id1)
        ->and($config->getDoToken())->toBe('token-1');

    // Switch default
    expect($config->setDefaultCloudAccount('do', $id2))->toBeTrue();
    expect($config->getDefaultCloudAccount('do')['id'])->toBe($id2)
        ->and($config->getDoToken())->toBe('token-2');

    // Remove first account
    expect($config->removeCloudAccount('do', $id1))->toBeTrue();
    expect($config->getCloudAccounts('do'))->toHaveCount(1)
        ->and($config->getDefaultCloudAccount('do')['id'])->toBe($id2);
});
