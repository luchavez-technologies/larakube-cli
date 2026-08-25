<?php

test('vpn management-config renders valid JSON wired to the external host', function (): void {
    $json = view('k8s.vpn.management-config', [
        'host' => 'vpn.example.com',
        'relaySecret' => 'super-secret-value',
        'dataStoreEncryptionKey' => 'super-secret-key',
    ])->render();

    $config = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($config['Signal']['URI'])->toBe('vpn.example.com:443')
        ->and($config['Signal']['Proto'])->toBe('https')
        ->and($config['Relay']['Addresses'])->toBe(['rels://vpn.example.com:443/relay'])
        ->and($config['Relay']['Secret'])->toBe('super-secret-value')
        ->and($config['DataStoreEncryptionKey'])->toBe('super-secret-key')
        ->and($config)->not->toHaveKey('HttpConfig')
        ->and($config['EmbeddedIdP'])->toBe([
            'Enabled' => true,
            'DataDir' => '/var/lib/netbird/idp',
            // Confirmed live 2026-08-25: without the /oauth2 suffix, Dex
            // initializes fine and /api/setup still works, but every
            // /oauth2/* route (the CLI's interactive SSO login) silently
            // 404s — Dex builds its route table from the issuer's own path.
            'Issuer' => 'https://vpn.example.com/oauth2',
        ])
        ->and($config['EncryptionKey'])->toBe('super-secret-key');
});
