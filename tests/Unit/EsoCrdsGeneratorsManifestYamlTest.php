<?php

use Symfony\Component\Yaml\Yaml;

test('eso-crds-generators manifest renders valid multi-document YAML with both generator CRDs', function () {
    $rendered = view('k8s.secrets.eso-crds-generators')->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    expect($documents)->toHaveCount(2);

    $names = [];
    foreach ($documents as $document) {
        try {
            $parsed = Yaml::parse($document);
            expect($parsed)->toBeArray()->and($parsed['kind'] ?? null)->toBe('CustomResourceDefinition');
            $names[] = $parsed['metadata']['name'] ?? null;
        } catch (Throwable $e) {
            echo "\n--- FAILED DOCUMENT ---\n".$document."\n--- END DOCUMENT ---\n";
            throw $e;
        }
    }

    expect($names)->toContain('clustergenerators.generators.external-secrets.io');
    expect($names)->toContain('vaultdynamicsecrets.generators.external-secrets.io');
});

test('neither generator CRD points its conversion strategy at an undeployed webhook', function () {
    // Regression guard: the official upstream CRD bundle declares
    // conversion.strategy: Webhook pointing at ESO's own conversion webhook
    // service — a component eso.blade.php never deploys (only the core
    // controller runs here). Both CRDs declare exactly one served version
    // (v1alpha1), so there's nothing to convert between; leaving Webhook in
    // place would just be a dangling pointer that misbehaves the moment K8s
    // ever tries to invoke it.
    $rendered = view('k8s.secrets.eso-crds-generators')->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    foreach ($documents as $document) {
        $parsed = Yaml::parse($document);
        expect($parsed['spec']['versions'] ?? [])->toHaveCount(1);
        expect($parsed['spec']['conversion']['strategy'] ?? null)->toBe('None');
        expect($parsed['spec']['conversion'])->not->toHaveKey('webhook');
    }
});
