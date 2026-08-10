<?php

use Symfony\Component\Yaml\Yaml;

function pocketbaseManifest(array $overrides = []): string
{
    return view('k8s.data.pocketbase', array_merge([
        'deployName' => 'data-pocketbase',
        'namespace' => 'larakube-shared',
        'instance' => 'main',
        'secretName' => 'data-secrets',
        'pvcName' => 'data-pocketbase-pvc',
        'host' => 'data.example.com',
        'isLocal' => false,
        'vpnOnly' => false,
        'proxied' => false,
    ], $overrides))->render();
}

/** @return array<int, array<string, mixed>> */
function pocketbaseDocuments(string $rendered): array
{
    return array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', $rendered)),
            fn (string $doc) => $doc !== '',
        )),
    );
}

test('pocketbase manifest renders as valid multi-document YAML', function () {
    $documents = pocketbaseDocuments(pocketbaseManifest());

    expect($documents)->not->toBeEmpty();

    foreach ($documents as $document) {
        expect($document)->toBeArray()->and($document['kind'] ?? null)->not->toBeNull();
    }
});

test('pocketbase manifest includes a hooks ConfigMap that bridges mail/SSO env vars', function () {
    $documents = pocketbaseDocuments(pocketbaseManifest());

    $configMap = collect($documents)->firstWhere('kind', 'ConfigMap');
    expect($configMap)->not->toBeNull();
    expect($configMap['metadata']['name'])->toBe('data-pocketbase-hooks');

    $hook = $configMap['data']['onBootstrap.pb.js'];
    expect($hook)
        ->toContain('onBootstrap(')
        ->toContain('$os.getenv("POCKETBASE_SMTP_HOST")')
        ->toContain('$os.getenv("POCKETBASE_SMTP_FROM")')
        ->toContain('$os.getenv("POCKETBASE_OIDC_CLIENT_ID")')
        ->toContain('$os.getenv("POCKETBASE_OIDC_CLIENT_SECRET")')
        ->toContain('$app.settings()')
        ->toContain('$app.save(settings)');
});

test('pocketbase deployment mounts the hooks ConfigMap at /pb_hooks', function () {
    $documents = pocketbaseDocuments(pocketbaseManifest());

    $deployment = collect($documents)->firstWhere('kind', 'Deployment');
    expect($deployment)->not->toBeNull();

    $container = $deployment['spec']['template']['spec']['containers'][0];
    $hookMount = collect($container['volumeMounts'])->firstWhere('mountPath', '/pb_hooks');
    expect($hookMount)->not->toBeNull();
    expect($hookMount['name'])->toBe('pb-hooks');

    $volume = collect($deployment['spec']['template']['spec']['volumes'])->firstWhere('name', 'pb-hooks');
    expect($volume)->not->toBeNull();
    expect($volume['configMap']['name'])->toBe('data-pocketbase-hooks');
});

test('pocketbase manifest renders valid YAML for local and vpn-only variants', function () {
    expect(pocketbaseDocuments(pocketbaseManifest(['isLocal' => true])))->not->toBeEmpty();
    expect(pocketbaseDocuments(pocketbaseManifest(['isLocal' => false, 'vpnOnly' => true])))->not->toBeEmpty();
    expect(pocketbaseDocuments(pocketbaseManifest(['isLocal' => true, 'vpnOnly' => true])))->not->toBeEmpty();
});

test('pocketbase deployment relies on the image\'s own entrypoint, not a broken custom command', function () {
    // Regression guard for a live crash (2026-08-08): a custom `command:`
    // called `/pocketbase`, but ghcr.io/muchobien/pocketbase installs the
    // binary at /usr/local/bin/pocketbase and its own entrypoint.sh already
    // handles superuser creation (via PB_ADMIN_EMAIL/PB_ADMIN_PASSWORD, not
    // ADMIN_EMAIL/ADMIN_PASSWORD) and the default serve command — including
    // --hooksDir=/pb_hooks, matching our ConfigMap mount. No command
    // override is needed at all.
    $documents = pocketbaseDocuments(pocketbaseManifest());
    $deployment = collect($documents)->firstWhere('kind', 'Deployment');
    $container = $deployment['spec']['template']['spec']['containers'][0];

    expect($container)->not->toHaveKey('command');

    $envNames = collect($container['env'])->pluck('name')->all();
    expect($envNames)->toContain('PB_ADMIN_EMAIL', 'PB_ADMIN_PASSWORD')
        ->not->toContain('ADMIN_EMAIL', 'ADMIN_PASSWORD');
});
