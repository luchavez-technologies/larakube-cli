<?php

use Symfony\Component\Yaml\Yaml;

test('vpn shared manifest renders as valid multi-document YAML', function () {
    $rendered = view('k8s.vpn.shared', ['host' => 'vpn.example.com'])->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    expect($documents)->not->toBeEmpty();

    $kinds = [];
    foreach ($documents as $document) {
        $parsed = Yaml::parse($document);
        expect($parsed)->toBeArray()->and($parsed['kind'] ?? null)->not->toBeNull();
        $kinds[] = $parsed['kind'].'/'.$parsed['metadata']['name'];
    }

    expect($kinds)->toContain('Deployment/netbird-management')
        ->toContain('Deployment/netbird-signal')
        ->toContain('Deployment/netbird-relay')
        ->toContain('Deployment/netbird-client')
        ->toContain('Ingress/netbird-management');
});
