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
        'bucket' => 'twenty-storage-crm-example-com',
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

test('crm manifest renders as valid multi-document YAML', function (): void {
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
test('both server and worker get the S3 storage env block with the exact S_3 casing', function (): void {
    $documents = crmDocuments(crmManifest());
    $deployments = collect($documents)->where('kind', 'Deployment');

    expect($deployments)->toHaveCount(2);

    foreach ($deployments as $deployment) {
        $env = collect($deployment['spec']['template']['spec']['containers'][0]['env']);
        $byName = $env->keyBy('name');

        expect($byName['STORAGE_TYPE']['value'])->toBe('S_3')
            ->and($byName['STORAGE_S3_NAME']['value'])->toBe('twenty-storage-crm-example-com')
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

test('worker Deployment skips migrations so it never races the server\'s boot-time schema init', function (): void {
    $documents = crmDocuments(crmManifest());
    $worker = collect($documents)->where('kind', 'Deployment')->firstWhere('metadata.name', 'twenty-worker-crm-example-com');

    $byName = collect($worker['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');

    expect($worker['spec']['template']['spec']['containers'][0]['command'])->toBe(['yarn', 'worker:prod'])
        ->and($byName['DISABLE_DB_MIGRATIONS']['value'])->toBe('true');
});

test('every name comes from ToolInstance and carries the identity labels', function (): void {
    $documents = crmDocuments(crmManifest());
    $byKind = fn (string $kind) => collect($documents)->where('kind', $kind);

    $server = $byKind('Deployment')->firstWhere('metadata.name', 'twenty-crm-example-com');
    $worker = $byKind('Deployment')->firstWhere('metadata.name', 'twenty-worker-crm-example-com');
    $service = $byKind('Service')->first();
    $env = collect($server['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');

    expect($server['metadata']['labels']['larakube.io/tool'])->toBe('crm')
        ->and($server['metadata']['labels']['larakube.io/component'])->toBe('twenty')
        ->and($server['metadata']['labels']['larakube.io/instance'])->toBe('crm-example-com')
        ->and($server['metadata']['labels']['larakube-tool'])->toBe('crm')
        ->and($server['spec']['template']['metadata']['labels']['larakube.io/instance'])->toBe('crm-example-com')
        ->and($worker['metadata']['labels']['larakube.io/component'])->toBe('twenty-worker')
        ->and($service['metadata']['name'])->toBe('twenty-crm-example-com')
        ->and($service['spec']['selector']['app'])->toBe('twenty-crm-example-com')
        ->and($env['DB_PASSWORD']['valueFrom']['secretKeyRef']['name'])->toBe('twenty-secrets-crm-example-com')
        ->and($env['PG_DATABASE_URL']['value'])->toContain('twenty_crm_example_com:$(DB_PASSWORD)@')
        ->and($env['PG_DATABASE_URL']['value'])->toEndWith('/twenty_crm_example_com')
        ->and($env['SSO_OIDC_ISSUER']['valueFrom']['secretKeyRef']['name'])->toBe('twenty-oidc-crm-example-com');
});

test('the Ingress routes to the Service by the same name and attaches the --vpn-only Middleware for its instance', function (): void {
    $documents = crmDocuments(crmManifest(['vpnOnly' => true]));
    $ingress = collect($documents)->firstWhere('kind', 'Ingress');

    expect($ingress['metadata']['name'])->toBe('twenty-crm-example-com')
        ->and($ingress['metadata']['labels']['larakube.io/instance'])->toBe('crm-example-com')
        ->and($ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'])->toBe('twenty-crm-example-com')
        ->and($ingress['metadata']['annotations']['traefik.ingress.kubernetes.io/router.middlewares'])
        ->toBe('larakube-shared-twenty-vpn-only-crm-example-com@kubernetescrd');
});

test('the Ingress renders from the host alone, the way the shared-service reconcile does', function (): void {
    $rendered = view('k8s.crm.ingress', ['host' => 'crm.example.com'])->render();

    expect($rendered)->toContain('name: twenty-crm-example-com')
        ->and($rendered)->toContain('larakube.io/instance: crm-example-com');
});
