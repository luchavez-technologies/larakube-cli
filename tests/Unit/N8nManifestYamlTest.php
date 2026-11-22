<?php

use Symfony\Component\Yaml\Yaml;

function n8nManifest(array $overrides = []): string
{
    return view('k8s.n8n.shared', array_merge([
        'host' => 'flow.luchtech.dev',
        'engine' => 'n8n',
        'volumeSize' => fn (string $claim, string $default, bool $growth = false) => $default,
        'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => false,
        'customEnvConfigMap' => 'flow-n8n-custom',
        'customEnvSecret' => 'flow-n8n-custom',
    ], $overrides))->render();
}

/** @return array<int, array<string, mixed>> */
function n8nDocuments(string $rendered): array
{
    return array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', $rendered)),
            fn (string $doc) => $doc !== '',
        )),
    );
}

test('n8n deployment wires the custom env overlay via envFrom, ahead of its own managed env', function (): void {
    $documents = n8nDocuments(n8nManifest());

    $deployment = collect($documents)->firstWhere('kind', 'Deployment');
    $container = $deployment['spec']['template']['spec']['containers'][0];

    // n8n's defaults come first, so a saved setting or custom variable wins.
    $defaults = $container['envFrom'][0]['configMapRef']['name'];
    expect($defaults)->toStartWith('n8n-defaults-')
        ->and(array_slice($container['envFrom'], 1))->toBe([
            ['configMapRef' => ['name' => 'flow-n8n-custom']],
            ['secretRef' => ['name' => 'flow-n8n-custom']],
        ])
        ->and(collect($documents)->firstWhere('metadata.name', $defaults)['data'])->toBe([
            'N8N_UNVERIFIED_PACKAGES_ENABLED' => 'true',
            'N8N_RUNNERS_TASK_TIMEOUT' => '300',
            'N8N_COMPRESSION_NODE_MAX_DECOMPRESSED_SIZE_BYTES' => '268435456',
            'N8N_COMPRESSION_NODE_MAX_ZIP_ENTRIES' => '1000',
        ]);

    $rendered = n8nManifest();
    expect(strpos($rendered, 'envFrom:'))->toBeLessThan(strpos($rendered, 'env:'));
});

test('n8n runs Code nodes in an external runners sidecar on the same version, sharing one token', function (): void {
    $deployment = collect(n8nDocuments(n8nManifest()))->firstWhere('kind', 'Deployment');
    $containers = collect($deployment['spec']['template']['spec']['containers'])->keyBy('name');
    $n8nEnv = collect($containers['n8n']['env'])->keyBy('name');
    $runnersEnv = collect($containers['runners']['env'])->keyBy('name');

    $version = fn (string $image): string => substr($image, strrpos($image, ':') + 1);

    expect($n8nEnv['N8N_RUNNERS_MODE']['value'])->toBe('external')
        ->and($runnersEnv['N8N_RUNNERS_TASK_BROKER_URI']['value'])->toBe('http://127.0.0.1:5679')
        // Both sides read the same Secret key, so the token always matches.
        ->and($n8nEnv['N8N_RUNNERS_AUTH_TOKEN']['valueFrom']['secretKeyRef']['key'])->toBe('runners-auth-token')
        ->and($runnersEnv['N8N_RUNNERS_AUTH_TOKEN']['valueFrom'])->toBe($n8nEnv['N8N_RUNNERS_AUTH_TOKEN']['valueFrom'])
        // The runners image must match n8n's version exactly.
        ->and($version($containers['runners']['image']))->toBe($version($containers['n8n']['image']))
        // The broker stays on its default loopback address, unexposed.
        ->and($n8nEnv->has('N8N_RUNNERS_BROKER_LISTEN_ADDRESS'))->toBeFalse()
        // Settings live in the defaults ConfigMap, never as fixed env a user can't override.
        ->and($n8nEnv->has('N8N_RUNNERS_TASK_TIMEOUT'))->toBeFalse()
        ->and($n8nEnv->has('N8N_UNVERIFIED_PACKAGES_ENABLED'))->toBeFalse();
});
