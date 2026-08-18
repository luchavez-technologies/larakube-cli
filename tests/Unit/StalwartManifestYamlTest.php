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

test('storeBootstrap renders a config.json ConfigMap and mounts it, referencing the password by name only', function () {
    // EXPERIMENTAL local-only wizard-skip: config.json must never embed the
    // actual password — authSecret references STALWART_STORE_PASSWORD by
    // NAME so Stalwart reads it from its own process env at boot.
    $rendered = view('k8s.mail.stalwart', [
        'host' => 'send.luchtech.dev',
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
        'hostPort' => true,
        'storeBootstrap' => [
            'password' => 'super-secret-password',
            'host' => 'postgres.larakube-plex.svc.cluster.local',
            'port' => 5432,
            'database' => 'stalwart',
            'username' => 'stalwart',
            'blob' => null,
            'redis' => null,
            'search' => ['type' => 'default'],
        ],
    ])->render();

    expect($rendered)->not->toContain('super-secret-password');

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    $configMap = null;
    $deployment = null;
    foreach ($documents as $document) {
        $parsed = Yaml::parse($document);
        if (($parsed['kind'] ?? null) === 'ConfigMap' && ($parsed['metadata']['name'] ?? null) === 'stalwart-config') {
            $configMap = $parsed;
        }
        if (($parsed['kind'] ?? null) === 'Deployment') {
            $deployment = $parsed;
        }
    }

    expect($configMap)->not->toBeNull();
    $configJson = json_decode($configMap['data']['config.json'], true);
    expect($configJson)->not->toBeNull()
        ->and($configJson['@type'])->toBe('PostgreSql')
        ->and($configJson['host'])->toBe('postgres.larakube-plex.svc.cluster.local')
        ->and($configJson['authSecret']['@type'])->toBe('EnvironmentVariable')
        ->and($configJson['authSecret']['variableName'])->toBe('STALWART_STORE_PASSWORD');

    $container = $deployment['spec']['template']['spec']['containers'][0];
    $storePasswordEnv = collect($container['env'])->firstWhere('name', 'STALWART_STORE_PASSWORD');
    expect($storePasswordEnv['valueFrom']['secretKeyRef']['name'])->toBe('mail-secrets')
        ->and($storePasswordEnv['valueFrom']['secretKeyRef']['key'])->toBe('store-password');

    $configMount = collect($container['volumeMounts'])->firstWhere('mountPath', '/etc/stalwart/config.json');
    expect($configMount)->not->toBeNull()
        ->and($configMount['name'])->toBe('stalwart-config')
        ->and($configMount['readOnly'])->toBeTrue();

    expect(collect($container['env'])->firstWhere('name', 'STALWART_SEARCH_MEILI_KEY'))->toBeNull();
});

test('storeBootstrap with blob + meilisearch wires STALWART_S3_* and STALWART_SEARCH_MEILI_KEY from mail-secrets', function () {
    $rendered = view('k8s.mail.stalwart', [
        'host' => 'send.test',
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
        'hostPort' => true,
        'storeBootstrap' => [
            'password' => 'super-secret-password',
            'host' => 'postgres.larakube-plex.svc.cluster.local',
            'port' => 5432,
            'database' => 'stalwart',
            'username' => 'stalwart',
            'blob' => [
                'backend' => 'seaweedfs',
                'endpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
                'bucket' => 'stalwart',
                'accessKey' => 'larakube',
                'secretKey' => 'super-secret-s3-key',
            ],
            'redis' => ['url' => 'redis://redis.larakube-plex.svc.cluster.local:6379/0'],
            'search' => ['type' => 'meilisearch', 'url' => 'http://meilisearch.larakube-plex.svc.cluster.local:7700', 'key' => 'super-secret-meili-key'],
        ],
    ])->render();

    expect($rendered)->not->toContain('super-secret-s3-key')
        ->not->toContain('super-secret-meili-key');

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

    $container = $deployment['spec']['template']['spec']['containers'][0];
    $env = collect($container['env']);

    $s3Key = $env->firstWhere('name', 'STALWART_S3_KEY_ID');
    expect($s3Key['valueFrom']['secretKeyRef'])->toBe(['name' => 'mail-secrets', 'key' => 's3-access-key']);

    $s3Secret = $env->firstWhere('name', 'STALWART_S3_SECRET_KEY');
    expect($s3Secret['valueFrom']['secretKeyRef'])->toBe(['name' => 'mail-secrets', 'key' => 's3-secret-key']);

    $meiliKey = $env->firstWhere('name', 'STALWART_SEARCH_MEILI_KEY');
    expect($meiliKey['valueFrom']['secretKeyRef'])->toBe(['name' => 'mail-secrets', 'key' => 'search-meili-key']);
});

test('without storeBootstrap, STALWART_STORE_PASSWORD still falls back to the optional stalwart secret', function () {
    $rendered = view('k8s.mail.stalwart', [
        'host' => 'send.luchtech.dev',
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => false,
        'hostPort' => true,
    ])->render();

    expect($rendered)->not->toContain('kind: ConfigMap');

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

    $container = $deployment['spec']['template']['spec']['containers'][0];
    $storePasswordEnv = collect($container['env'])->firstWhere('name', 'STALWART_STORE_PASSWORD');
    expect($storePasswordEnv['valueFrom']['secretKeyRef']['name'])->toBe('stalwart')
        ->and($storePasswordEnv['valueFrom']['secretKeyRef']['optional'])->toBeTrue();
});
