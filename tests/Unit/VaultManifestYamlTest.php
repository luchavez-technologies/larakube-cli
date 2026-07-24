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
