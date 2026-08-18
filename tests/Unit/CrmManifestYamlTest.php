<?php

use Symfony\Component\Yaml\Yaml;

function crmManifest(array $overrides = []): string
{
    return view('k8s.crm.shared', array_merge([
        'host' => 'crm.example.com',
        'plexNamespace' => 'larakube-plex',
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => false,
        'redisIndex' => 0,
        'instance' => 'crm-example-com',
        'deploymentName' => 'crm-twenty-crm-example-com',
        'workerDeploymentName' => 'crm-twenty-worker-crm-example-com',
        'serviceName' => 'crm-crm-example-com',
        'ingressName' => 'crm-crm-example-com',
        'secretName' => 'crm-secrets-crm-example-com',
        'oidcSecretName' => 'crm-oidc-crm-example-com',
        'dbUser' => 'crm_twenty_crm_example_com',
        'dbName' => 'crm_twenty_crm_example_com',
        'bucket' => 'crm-twenty-storage-crm-example-com',
        's3InternalEndpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
        's3PublicEndpoint' => 'https://files.example.com',
    ], $overrides))->render();
}

/** @return array<int, array<string, mixed>> */
function crmDocuments(string $rendered): array
{
    return array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', $rendered)),
            fn (string $doc) => $doc !== '',
        )),
    );
}

test('crm manifest renders as valid multi-document YAML', function () {
    $documents = crmDocuments(crmManifest());

    expect($documents)->not->toBeEmpty();

    foreach ($documents as $document) {
        expect($document)->toBeArray()->and($document['kind'] ?? null)->not->toBeNull();
    }
});

/**
 * Regression: Twenty's S3 storage type is the literal enum value "S_3" (with
 * an underscore) per its own config-variables.ts — "s3"/"S3" both fail
 * validation silently and Twenty falls back to STORAGE_TYPE=local, which
 * loses every attachment on the next pod restart. This pins the exact
 * casing so it can never regress back to the natural-looking "s3".
 */
test('both server and worker get the S3 storage env block with the exact S_3 casing', function () {
    $documents = crmDocuments(crmManifest());
    $deployments = collect($documents)->where('kind', 'Deployment');

    expect($deployments)->toHaveCount(2);

    foreach ($deployments as $deployment) {
        $env = collect($deployment['spec']['template']['spec']['containers'][0]['env']);
        $byName = $env->keyBy('name');

        expect($byName['STORAGE_TYPE']['value'])->toBe('S_3')
            ->and($byName['STORAGE_S3_NAME']['value'])->toBe('crm-twenty-storage-crm-example-com')
            ->and($byName['STORAGE_S3_ENDPOINT']['value'])->toBe('http://seaweedfs.larakube-plex.svc.cluster.local:8333')
            ->and($byName['STORAGE_S3_ACCESS_KEY_ID']['valueFrom']['secretKeyRef']['key'])->toBe('s3-key')
            ->and($byName['STORAGE_S3_SECRET_ACCESS_KEY']['valueFrom']['secretKeyRef']['key'])->toBe('s3-secret')
            ->and($byName['STORAGE_S3_PRESIGNED_URL_ENABLED']['value'])->toBe('true')
            // The PUBLIC endpoint, never the cluster-internal one — SeaweedFS
            // denies anonymous reads, so a browser resolving an attachment
            // link needs a host it can actually reach.
            ->and($byName['STORAGE_S3_PRESIGNED_URL_BASE']['value'])->toBe('https://files.example.com');
    }
});

test('worker Deployment skips migrations so it never races the server\'s boot-time schema init', function () {
    $documents = crmDocuments(crmManifest());
    $worker = collect($documents)->where('kind', 'Deployment')->firstWhere('metadata.name', 'crm-twenty-worker-crm-example-com');

    $byName = collect($worker['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');

    expect($worker['spec']['template']['spec']['containers'][0]['command'])->toBe(['yarn', 'worker:prod'])
        ->and($byName['DISABLE_DB_MIGRATIONS']['value'])->toBe('true');
});
