<?php

use Symfony\Component\Yaml\Yaml;

function directusManifest(array $overrides = []): string
{
    return view('k8s.data.directus', array_merge([
        'deployName' => 'data-directus',
        'secretName' => 'data-secrets',
        'smtpSecretName' => 'data-smtp',
        'oidcSecretName' => 'data-oidc',
        'dbName' => 'data_directus',
        'bucket' => 'data-directus-storage',
        'host' => 'data.example.com',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 0,
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => false,
        'authProviders' => 'local',
    ], $overrides))->render();
}

/** @return array<int, array<string, mixed>> */
function directusDocuments(string $rendered): array
{
    return array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', $rendered)),
            fn (string $doc) => $doc !== '',
        )),
    );
}

test('directus manifest renders as valid multi-document YAML for main and a named instance', function () {
    foreach ([[], ['deployName' => 'data-directus-blog', 'secretName' => 'data-secrets-blog', 'smtpSecretName' => 'data-smtp-blog', 'oidcSecretName' => 'data-oidc-blog', 'dbName' => 'data_directus_blog', 'bucket' => 'data-directus-storage-blog']] as $overrides) {
        $documents = directusDocuments(directusManifest($overrides));

        expect($documents)->not->toBeEmpty();

        foreach ($documents as $document) {
            expect($document)->toBeArray()->and($document['kind'] ?? null)->not->toBeNull();
        }
    }
});

test('directus main instance keeps the original unsuffixed resource names', function () {
    $documents = directusDocuments(directusManifest());

    $deployment = collect($documents)->firstWhere('kind', 'Deployment');
    $service = collect($documents)->firstWhere('kind', 'Service');
    $ingress = collect($documents)->firstWhere('kind', 'Ingress');

    expect($deployment['metadata']['name'])->toBe('data-directus');
    expect($service['metadata']['name'])->toBe('data-directus');
    expect($ingress['metadata']['name'])->toBe('data-directus');
    expect($ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'])->toBe('data-directus');
});

test('directus named instance gets distinct Deployment/Service/Ingress names, not shared "data"', function () {
    // Regression guard: before this fix, directus.blade.php and its shared
    // k8s.data.ingress partial hardcoded "data-directus"/"data"/"data_directus"
    // regardless of instance — two Directus instances collided on Deployment,
    // Service, Secret, DB, bucket, and Ingress name, independent of the host
    // question the rest of this pass fixed.
    $documents = directusDocuments(directusManifest([
        'deployName' => 'data-directus-blog',
        'secretName' => 'data-secrets-blog',
        'smtpSecretName' => 'data-smtp-blog',
        'oidcSecretName' => 'data-oidc-blog',
        'dbName' => 'data_directus_blog',
        'bucket' => 'data-directus-storage-blog',
    ]));

    $deployment = collect($documents)->firstWhere('kind', 'Deployment');
    $service = collect($documents)->firstWhere('kind', 'Service');
    $ingress = collect($documents)->firstWhere('kind', 'Ingress');

    expect($deployment['metadata']['name'])->toBe('data-directus-blog');
    expect($service['metadata']['name'])->toBe('data-directus-blog');
    expect($ingress['metadata']['name'])->toBe('data-directus-blog');
    expect($ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'])->toBe('data-directus-blog');

    $rendered = directusManifest([
        'deployName' => 'data-directus-blog',
        'secretName' => 'data-secrets-blog',
        'smtpSecretName' => 'data-smtp-blog',
        'oidcSecretName' => 'data-oidc-blog',
        'dbName' => 'data_directus_blog',
        'bucket' => 'data-directus-storage-blog',
    ]);
    expect($rendered)
        ->toContain('data-secrets-blog')
        ->toContain('data-smtp-blog')
        ->toContain('data-oidc-blog')
        ->toContain('data_directus_blog')
        ->toContain('data-directus-storage-blog');
});
