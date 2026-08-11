<?php

use Symfony\Component\Yaml\Yaml;

test('vaultwarden manifest renders valid multi-document YAML', function () {
    $rendered = view('k8s.vault.shared', [
        'host' => 'vault.luchtech.dev',
        'adminToken' => 'd7ec8024f301823b687b6e9ad6ba797c',
        'hashedAdminToken' => password_hash('d7ec8024f301823b687b6e9ad6ba797c', PASSWORD_ARGON2ID),
        'databaseUrl' => 'postgresql://vaultwarden:mY-S3cr3t-P@ssw0rd!@postgres.larakube-plex.svc.cluster.local:5432/vaultwarden',
        'isLocal' => false,
        'vpnOnly' => false,
    ])->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    expect($documents)->not->toBeEmpty();

    foreach ($documents as $document) {
        try {
            $parsed = Yaml::parse($document);
            expect($parsed)->toBeArray()->and($parsed['kind'] ?? null)->not->toBeNull();
        } catch (Throwable $e) {
            echo "\n--- FAILED DOCUMENT ---\n".$document."\n--- END DOCUMENT ---\n";
            throw $e;
        }
    }
});

test('vaultwarden readiness is DB-backed so a stale DATABASE_URL reports NotReady instead of 503ing', function () {
    // Devsecops availability posture: /api/health checks the DB, so a pod
    // running against a stale DATABASE_URL (the 2026-08-10 SSO outage) leaves
    // the Service endpoints instead of accepting traffic and 503ing. Liveness
    // stays on `/` so a DB blip doesn't crash-loop the pod.
    $rendered = view('k8s.vault.shared', [
        'host' => 'vault.luchtech.dev',
        'adminToken' => 'd7ec8024f301823b687b6e9ad6ba797c',
        'hashedAdminToken' => password_hash('d7ec8024f301823b687b6e9ad6ba797c', PASSWORD_ARGON2ID),
        'databaseUrl' => 'postgresql://vaultwarden:mY-S3cr3t-P@ssw0rd!@postgres.larakube-plex.svc.cluster.local:5432/vaultwarden',
        'isLocal' => false,
        'vpnOnly' => false,
    ])->render();

    $documents = array_map(fn (string $doc) => Yaml::parse($doc), array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    )));

    $deployment = collect($documents)->first(fn ($doc) => ($doc['kind'] ?? null) === 'Deployment');
    $container = $deployment['spec']['template']['spec']['containers'][0];

    expect($container['readinessProbe']['httpGet']['path'])->toBe('/api/health')
        ->and($container['livenessProbe']['httpGet']['path'])->toBe('/')
        ->and($container['startupProbe']['httpGet']['path'])->toBe('/');
});
