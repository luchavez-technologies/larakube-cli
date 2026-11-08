<?php

use App\Data\GlobalConfigData;

/**
 * Cloudflare used to be a bare single-slot field with no multi-account
 * support at all — ADR 0029 moves it onto the same cloudAccounts registry
 * DO/Hetzner already use, via the same legacy-migration + mirroring logic.
 */
test('it auto-migrates a legacy cloudflareToken into cloudAccounts', function (): void {
    $config = new GlobalConfigData(cloudflareToken: 'cf_legacy_token');

    $accounts = $config->getCloudAccounts('cloudflare');

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]['token'])->toBe('cf_legacy_token')
        ->and($accounts[0]['default'])->toBeTrue()
        ->and($config->getCloudflareToken())->toBe('cf_legacy_token');
});

test('setCloudflareToken creates a default account the same way setDoToken does', function (): void {
    $config = new GlobalConfigData;

    $config->setCloudflareToken('cf_new_token');

    expect($config->getCloudAccounts('cloudflare'))->toHaveCount(1)
        ->and($config->getDefaultCloudAccount('cloudflare')['token'])->toBe('cf_new_token');
});

test('cloudflare supports multiple named accounts, same as do/hetzner', function (): void {
    $config = new GlobalConfigData;

    $id1 = $config->addCloudAccount('cloudflare', 'Personal', 'cf-token-1', asDefault: true);
    $id2 = $config->addCloudAccount('cloudflare', 'Agency Client', 'cf-token-2', asDefault: false);

    expect($config->getCloudAccounts('cloudflare'))->toHaveCount(2)
        ->and($config->getCloudflareToken())->toBe('cf-token-1');

    $config->setDefaultCloudAccount('cloudflare', $id2);
    expect($config->getCloudflareToken())->toBe('cf-token-2');

    $config->removeCloudAccount('cloudflare', $id2);
    expect($config->getCloudAccounts('cloudflare'))->toHaveCount(1)
        ->and($config->getDefaultCloudAccount('cloudflare')['id'])->toBe($id1);
});
