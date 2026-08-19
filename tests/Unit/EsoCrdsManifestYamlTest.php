<?php

use Symfony\Component\Yaml\Yaml;

test('eso-crds manifest renders valid multi-document YAML', function (): void {
    $rendered = view('k8s.secrets.eso-crds')->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    expect($documents)->toHaveCount(5);

    foreach ($documents as $document) {
        try {
            $parsed = Yaml::parse($document);
            expect($parsed)->toBeArray()->and($parsed['kind'] ?? null)->toBe('CustomResourceDefinition');
        } catch (Throwable $e) {
            echo "\n--- FAILED DOCUMENT ---\n".$document."\n--- END DOCUMENT ---\n";
            throw $e;
        }
    }
});

test('every ESO CRD declares a status subresource', function (): void {
    // Regression guard: these are hand-rolled CRDs (permissive
    // x-kubernetes-preserve-unknown-fields schema, not pulled from ESO's
    // official manifests), and none of the 5 declared `subresources:
    // {status: {}}`. Kubernetes has no /status endpoint at all for a CRD
    // without this — every status patch the External Secrets Operator
    // controller makes 404s with "<resource> not found", even though the
    // resource itself exists and the actual secret sync succeeds. Found
    // live 2026-07-31: ExternalSecret/ClusterSecretStore status was
    // permanently empty despite secrets syncing correctly, which made the
    // whole stack look broken when it wasn't.
    $rendered = view('k8s.secrets.eso-crds')->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    foreach ($documents as $document) {
        $parsed = Yaml::parse($document);
        $name = $parsed['metadata']['name'] ?? 'unknown';

        foreach ($parsed['spec']['versions'] ?? [] as $version) {
            expect($version)->toHaveKey('subresources')
                ->and($version['subresources'])->toHaveKey('status');
        }
    }
});
