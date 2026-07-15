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
        ->toContain('Ingress/netbird-management');
});

test('vpn ingress requests a real ACME cert for a cloud install, never a local one', function () {
    $cloud = view('k8s.vpn.shared', ['host' => 'vpn.example.com', 'isLocal' => false])->render();
    $local = view('k8s.vpn.shared', ['host' => 'vpn.dev.test', 'isLocal' => true])->render();

    expect($cloud)->toContain('traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt')
        ->and($local)->not->toContain('router.tls.certresolver');
});

test('vpn management and signal Services both request h2c backend proxying — both serve gRPC', function () {
    $rendered = view('k8s.vpn.shared', ['host' => 'vpn.example.com'])->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    foreach (['netbird-management', 'netbird-signal'] as $name) {
        $service = collect($documents)
            ->map(fn (string $doc) => Yaml::parse($doc))
            ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Service' && ($doc['metadata']['name'] ?? null) === $name);

        expect($service)->not->toBeNull("Service/{$name} not found in the rendered manifest")
            ->and($service['metadata']['annotations']['traefik.ingress.kubernetes.io/service.serversscheme'] ?? null)->toBe('h2c');
    }
});

test('vpn client manifest renders as valid multi-document YAML wired to the bootstrapped setup key', function () {
    $rendered = view('k8s.vpn.client')->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    $deployment = collect($documents)
        ->map(fn (string $doc) => Yaml::parse($doc))
        ->first(fn (array $doc) => ($doc['kind'] ?? null) === 'Deployment');

    expect($deployment['metadata']['name'])->toBe('netbird-client');

    $env = $deployment['spec']['template']['spec']['containers'][0]['env'];
    $setupKeyEnv = collect($env)->firstWhere('name', 'NB_SETUP_KEY');

    expect($setupKeyEnv['valueFrom']['secretKeyRef'])->toBe([
        'name' => 'netbird-admin',
        'key' => 'setup-key',
    ]);
});
