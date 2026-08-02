<?php

use Symfony\Component\Yaml\Yaml;

test('openbao manifest renders valid multi-document YAML', function () {
    $rendered = view('k8s.secrets.openbao', [
        'namespace' => 'larakube-secrets',
        'image' => 'openbao/openbao:2.6.1',
        'port' => 8200,
        'host' => 'secrets.luchtech.dev',
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

test('openbao data volume is a PersistentVolumeClaim, not emptyDir', function () {
    // Regression guard: OpenBao's own secret store (storage "file" in
    // bao.hcl, mounted at /openbao/data) was shipped on emptyDir — wiped on
    // any pod restart/reschedule, with nothing to restore from. Found live
    // 2026-07-31, zero restarts so far, but a landmine. Fixed by adding a
    // dedicated PVC; this test locks the fix in.
    $rendered = view('k8s.secrets.openbao', [
        'namespace' => 'larakube-secrets',
        'image' => 'openbao/openbao:2.6.1',
        'port' => 8200,
        'host' => null,
    ])->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    $pvc = null;
    $deployment = null;
    foreach ($documents as $document) {
        $parsed = Yaml::parse($document);
        if (($parsed['kind'] ?? null) === 'PersistentVolumeClaim' && ($parsed['metadata']['name'] ?? null) === 'openbao-data') {
            $pvc = $parsed;
        }
        if (($parsed['kind'] ?? null) === 'Deployment' && ($parsed['metadata']['name'] ?? null) === 'openbao-backend') {
            $deployment = $parsed;
        }
    }

    expect($pvc)->not->toBeNull();
    expect($pvc['spec']['accessModes'] ?? null)->toBe(['ReadWriteOnce']);

    expect($deployment)->not->toBeNull();
    $volumes = $deployment['spec']['template']['spec']['volumes'] ?? [];
    $dataVolume = collect($volumes)->firstWhere('name', 'data');

    expect($dataVolume)->not->toBeNull();
    expect($dataVolume)->not->toHaveKey('emptyDir');
    expect($dataVolume['persistentVolumeClaim']['claimName'] ?? null)->toBe('openbao-data');
});

test('openbao runs under its own ServiceAccount with a system:auth-delegator binding', function () {
    // Needed for OpenBao's Vault Kubernetes auth backend to validate other
    // pods' ServiceAccount tokens via the TokenReview API — without this,
    // auth/kubernetes/login rejects every request. Dedicated SA (not the
    // implicit "default") so this permission is scoped to OpenBao alone.
    $rendered = view('k8s.secrets.openbao', [
        'namespace' => 'larakube-secrets',
        'image' => 'openbao/openbao:2.6.1',
        'port' => 8200,
        'host' => null,
    ])->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    $sa = null;
    $binding = null;
    $deployment = null;
    foreach ($documents as $document) {
        $parsed = Yaml::parse($document);
        if (($parsed['kind'] ?? null) === 'ServiceAccount' && ($parsed['metadata']['name'] ?? null) === 'openbao') {
            $sa = $parsed;
        }
        if (($parsed['kind'] ?? null) === 'ClusterRoleBinding' && ($parsed['metadata']['name'] ?? null) === 'openbao-auth-delegator') {
            $binding = $parsed;
        }
        if (($parsed['kind'] ?? null) === 'Deployment' && ($parsed['metadata']['name'] ?? null) === 'openbao-backend') {
            $deployment = $parsed;
        }
    }

    expect($sa)->not->toBeNull();
    expect($binding)->not->toBeNull();
    expect($binding['roleRef']['name'] ?? null)->toBe('system:auth-delegator');
    expect($binding['subjects'][0]['name'] ?? null)->toBe('openbao');

    expect($deployment['spec']['template']['spec']['serviceAccountName'] ?? null)->toBe('openbao');
});

test('openbao has no auto-unseal hook by default (cloud/production stay manual)', function () {
    $rendered = view('k8s.secrets.openbao', [
        'namespace' => 'larakube-secrets',
        'image' => 'openbao/openbao:2.6.1',
        'port' => 8200,
        'host' => null,
    ])->render();

    expect($rendered)->not->toContain('postStart')
        ->and($rendered)->not->toContain('bao operator unseal');
});

test('openbao gets an auto-unseal postStart hook when autoUnseal is true', function () {
    $rendered = view('k8s.secrets.openbao', [
        'namespace' => 'larakube-secrets',
        'image' => 'openbao/openbao:2.6.1',
        'port' => 8200,
        'host' => null,
        'autoUnseal' => true,
    ])->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    $deployment = null;
    foreach ($documents as $document) {
        $parsed = Yaml::parse($document);
        if (($parsed['kind'] ?? null) === 'Deployment' && ($parsed['metadata']['name'] ?? null) === 'openbao-backend') {
            $deployment = $parsed;
        }
    }

    expect($deployment)->not->toBeNull();
    $container = $deployment['spec']['template']['spec']['containers'][0];
    expect($container['lifecycle']['postStart']['exec']['command'] ?? null)->not->toBeNull();

    $volumes = $deployment['spec']['template']['spec']['volumes'] ?? [];
    $bootstrap = collect($volumes)->firstWhere('name', 'bootstrap');
    expect($bootstrap)->not->toBeNull();
    // optional: true — a fresh install (before secrets:init creates
    // openbao-bootstrap) must still start; the hook just no-ops.
    expect($bootstrap['secret']['optional'] ?? null)->toBeTrue();
    expect($bootstrap['secret']['secretName'] ?? null)->toBe('openbao-bootstrap');
});
