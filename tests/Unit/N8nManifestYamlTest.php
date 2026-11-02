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

    expect($container['envFrom'])->toBe([
        ['configMapRef' => ['name' => 'flow-n8n-custom']],
        ['secretRef' => ['name' => 'flow-n8n-custom']],
    ]);

    $rendered = n8nManifest();
    expect(strpos($rendered, 'envFrom:'))->toBeLessThan(strpos($rendered, 'env:'));
});
