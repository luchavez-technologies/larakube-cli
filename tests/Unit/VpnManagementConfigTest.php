<?php

test('vpn management-config renders valid JSON wired to the external host', function () {
    $json = view('k8s.vpn.management-config', [
        'host' => 'vpn.example.com',
        'relaySecret' => 'super-secret-value',
    ])->render();

    $config = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($config['Signal']['URI'])->toBe('vpn.example.com:443')
        ->and($config['Signal']['Proto'])->toBe('https')
        ->and($config['Relay']['Addresses'])->toBe(['rels://vpn.example.com:443/relay'])
        ->and($config['Relay']['Secret'])->toBe('super-secret-value');
});
