<?php

use Symfony\Component\Yaml\Yaml;

test('stalwart probes hit /healthz/{ready,live}, never a bare tcpSocket check', function () {
    // Regression guard: a plain tcpSocket probe only confirms the listener
    // accepts a connection — it stays green even while Stalwart is
    // internally stuck/deadlocked, so Kubernetes never notices or
    // auto-restarts it. Confirmed live 2026-08-02: the admin UI/Bulwark
    // silently stopped answering with no crash, no restart, and no signal
    // until a human hit the login page — the exact failure Stalwart's own
    // /healthz endpoints exist to catch (each returns non-200 when stuck).
    $rendered = view('k8s.mail.stalwart', [
        'host' => 'send.example.com',
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => false,
        'hostPort' => true,
    ])->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    $deployment = null;
    foreach ($documents as $document) {
        $parsed = Yaml::parse($document);
        if (($parsed['kind'] ?? null) === 'Deployment') {
            $deployment = $parsed;
        }
    }

    expect($deployment)->not->toBeNull();

    $container = $deployment['spec']['template']['spec']['containers'][0];

    expect($container)->not->toHaveKey('readinessProbe.tcpSocket')
        ->and($container['readinessProbe']['httpGet']['path'])->toBe('/healthz/ready')
        ->and($container['livenessProbe']['httpGet']['path'])->toBe('/healthz/live');
});
