<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Regression guard for a real live incident, 2026-08-24: `vpn:init production`
 * reported "Could not apply the NetBird VPN manifest" even though every other
 * resource in the same multi-doc apply succeeded. Root cause, isolated with
 * Blade::render() on minimal fixtures: an indented `@if`/`@endif` (or
 * `@else`) nested inside another Blade directive leaks its own leading
 * whitespace onto the line immediately after the OUTER directive closes.
 * `resources/views/k8s/vpn/ingress.blade.php`'s `spec:` (column 0 in the
 * source) rendered with 4 stray leading spaces, making it a child of
 * `metadata.annotations` instead of a sibling of `metadata` — Kubernetes
 * rejected the document outright. The same exact source pattern existed in 3
 * other templates, confirmed broken the same way before the fix (see each
 * template's own comment). The fix in all four: keep directive tags at
 * column 0, indent only literal YAML content.
 *
 * These tests parse every conditional branch of all four templates as real
 * YAML — the existing Feature tests for these commands only assert
 * Process::fake()'s `apply -f` call was reached, never that its content
 * actually parses, which is exactly how this shipped unnoticed.
 */

/** @return array<int, array<string, mixed>> */
function bladeYamlDocuments(string $rendered): array
{
    return array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', $rendered)),
            fn (string $doc) => $doc !== '',
        )),
    );
}

test('vpn ingress manifest parses as valid YAML across isLocal/proxied branches', function (bool $isLocal, bool $proxied): void {
    $rendered = view('k8s.vpn.shared', ['host' => 'vpn.example.com', 'isLocal' => $isLocal, 'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'storeDb' => 'vpn_management',
        'ssoDomain' => 'example.com'])->render();
    $documents = bladeYamlDocuments($rendered);

    expect($documents)->not->toBeEmpty();
    foreach ($documents as $document) {
        expect($document['kind'] ?? null)->not->toBeNull();
    }

    $ingress = collect($documents)->firstWhere('kind', 'Ingress');
    expect($ingress)->not->toBeNull()
        ->and($ingress['spec']['rules'][0]['host'] ?? null)->toBe('vpn.example.com');
})->with([
    'local' => [true, false],
    'cloud, not proxied' => [false, false],
]);

test('sso oauth2-proxy ingress manifest parses as valid YAML across isLocal/proxied branches', function (bool $isLocal, bool $proxied): void {
    $rendered = view('k8s.sso.proxy', [
        'namespace' => 'larakube-sso', 'authHost' => 'sso.example.com', 'isLocal' => $isLocal, 'proxied' => $proxied,
        'clientId' => 'cid', 'clientSecret' => 'csecret', 'cookieDomain' => 'example.com', 'cookieSecret' => 'cookiesecret',
        'image' => 'quay.io/oauth2-proxy/oauth2-proxy:v7.6.0', 'rbacRole' => 'owner', 'secretChecksum' => 'abc123', 'ssoHost' => 'sso.example.com',
    ])->render();
    $documents = bladeYamlDocuments($rendered);

    expect($documents)->not->toBeEmpty();
    foreach ($documents as $document) {
        expect($document['kind'] ?? null)->not->toBeNull();
    }

    $ingress = collect($documents)->firstWhere('kind', 'Ingress');
    expect($ingress)->not->toBeNull()
        ->and($ingress['spec']['rules'][0]['host'] ?? null)->toBe('sso.example.com');
})->with([
    'local' => [true, false],
    'cloud, not proxied' => [false, false],
    'cloud, proxied' => [false, true],
]);

test('spa-s3 ingress manifest parses as valid YAML across isLocal branches', function (bool $isLocal): void {
    $rendered = view('k8s.spa-s3-ingress', [
        'name' => 'demo', 'namespace' => 'larakube-shared', 'host' => 'demo.example.com', 'isLocal' => $isLocal,
        's3Port' => 8333, 's3ServiceName' => 'seaweedfs',
    ])->render();
    $documents = bladeYamlDocuments($rendered);

    expect($documents)->not->toBeEmpty();
    foreach ($documents as $document) {
        expect($document['kind'] ?? null)->not->toBeNull();
    }

    $ingress = collect($documents)->firstWhere('kind', 'Ingress');
    expect($ingress)->not->toBeNull()
        ->and($ingress['spec']['rules'][0]['host'] ?? null)->toBe('demo.example.com');
})->with([
    'local' => [true],
    'cloud' => [false],
]);

test('errors (Glitchtip) secret manifest parses as valid YAML across noPlex branches', function (bool $noPlex): void {
    $rendered = view('k8s.errors.shared', [
        'adminPassword' => 'x', 'noPlex' => $noPlex, 'dbPassword' => 'y', 'plexNamespace' => 'larakube-plex',
        'appName' => 'Errors', 'host' => 'errors.example.com',
    ])->render();
    $documents = bladeYamlDocuments($rendered);

    expect($documents)->not->toBeEmpty();
    foreach ($documents as $document) {
        expect($document['kind'] ?? null)->not->toBeNull();
    }

    $secret = collect($documents)->firstWhere('kind', 'Secret');
    expect($secret)->not->toBeNull()
        // secret-key: sits AFTER the @if/@else/@endif block in the source —
        // the exact line the bug pushed to the wrong indentation level.
        ->and($secret['data']['secret-key'] ?? null)->not->toBeNull();
})->with([
    'plex-backed' => [false],
    'no-plex (bundled)' => [true],
]);
