<?php

use Symfony\Component\Yaml\Yaml;

test('eso-crds-generators manifest renders valid multi-document YAML with all three generator CRDs', function (): void {
    $rendered = view('k8s.secrets.eso-crds-generators')->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    // GeneratorState joined ClusterGenerator/VaultDynamicSecret in this
    // bundle after being found live-required on prod 2026-08-23: ESO's
    // "stateful generators" feature (introduced v0.14.0) makes every
    // generator-backed ExternalSecret — including every VaultDynamicSecret
    // wiring this CLI creates via secrets:wire — fail every reconcile with
    // `no matches for kind "GeneratorState"` without it. Confirmed on the
    // v0.16.2 upgrade: the main external-secrets controller crash-looped on
    // this error for every wired tool until this CRD was vendored too.
    expect($documents)->toHaveCount(3);

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

    expect($names)->toContain('clustergenerators.generators.external-secrets.io')
        ->toContain('generatorstates.generators.external-secrets.io')
        ->toContain('vaultdynamicsecrets.generators.external-secrets.io');
});

test('neither generator CRD points its conversion strategy at an undeployed webhook', function (): void {
    // Regression guard, originally against ESO's v0.11.0-era upstream CRD
    // bundle, which declared conversion.strategy: Webhook pointing at ESO's
    // own conversion webhook service — a component eso.blade.php never
    // deployed (only the core controller ran at that version). Both CRDs
    // declare exactly one served version (v1alpha1), so there was nothing
    // to convert between; a Webhook strategy there was just a dangling
    // pointer that would misbehave the moment K8s ever tried to invoke it.
    // As of the v0.16.2 vendor pass, the official bundle no longer declares
    // a `conversion` block for either CRD at all — Kubernetes treats an
    // absent conversion strategy as None implicitly, which is the same safe
    // outcome this guard originally had to enforce by hand.
    $rendered = view('k8s.secrets.eso-crds-generators')->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    foreach ($documents as $document) {
        $parsed = Yaml::parse($document);
        $strategy = $parsed['spec']['conversion']['strategy'] ?? 'None';

        expect($parsed['spec']['versions'] ?? [])->toHaveCount(1)
            ->and($strategy)->toBe('None')
            ->and($parsed['spec']['conversion'] ?? [])->not->toHaveKey('webhook');
    }
});
