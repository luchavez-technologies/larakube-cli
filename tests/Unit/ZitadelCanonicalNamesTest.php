<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ResourceNaming;
use App\Enums\SecretKind;
use Symfony\Component\Yaml\Yaml;

/** @return list<array<string, mixed>> */
function zitadelDocuments(array $overrides = []): array
{
    $rendered = view('k8s.sso.zitadel', array_merge([
        'host' => 'sso.example.com',
        'instance' => 'sso-example-com',
        'adminEmail' => 'admin@example.com',
        'plexNamespace' => 'larakube-plex',
        'noPlex' => false,
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => false,
        'volumeSize' => fn (string $claim, string $default): string => $default,
    ], $overrides))->render();

    return array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $rendered)), fn (string $doc) => $doc !== '')),
    );
}

test('Zitadel resolves every resource name from ToolInstance with the category gone', function (): void {
    $names = ToolInstance::forInstance(ClusterTool::SSO, 'sso-luchtech-dev');

    expect($names->deployment())->toBe('zitadel-sso-luchtech-dev')
        ->and($names->secret())->toBe('zitadel-secrets-sso-luchtech-dev')
        ->and($names->database())->toBe('zitadel_sso_luchtech_dev')
        ->and($names->deployment('db'))->toBe('zitadel-db-sso-luchtech-dev')
        ->and($names->volume('storage', 'db'))->toBe('zitadel-db-storage-sso-luchtech-dev')
        ->and($names->deployment('proxy'))->toBe('proxy-sso-luchtech-dev')
        ->and($names->secret(SecretKind::CREDENTIALS, 'proxy'))->toBe('proxy-secrets-sso-luchtech-dev')
        ->and($names->secret(SecretKind::SSO_APP, 'proxy'))->toBe('proxy-sso-sso-luchtech-dev')
        ->and(ClusterTool::SSO->resourceNaming())->toBe(ResourceNaming::CANONICAL)
        ->and(ClusterTool::ZITADEL->resourceNaming())->toBe(ResourceNaming::CANONICAL);
});

test('the rotation Secret is the credentials Secret the manifest reads', function (): void {
    expect(ClusterTool::SSO->dbSecretRef('sso-luchtech-dev')['secret'])->toBe('zitadel-secrets-sso-luchtech-dev');
});

test('the manifest names every object from ToolInstance and points Zitadel at the tenant database', function (): void {
    $documents = collect(zitadelDocuments());
    $deployment = $documents->firstWhere('kind', 'Deployment');
    $service = $documents->firstWhere('kind', 'Service');
    $ingress = $documents->firstWhere('kind', 'Ingress');
    $env = collect($deployment['spec']['template']['spec']['containers'][0]['env'] ?? $deployment['spec']['template']['spec']['initContainers'][0]['env'])->keyBy('name');

    expect($deployment['metadata']['name'])->toBe('zitadel-sso-example-com')
        ->and($deployment['spec']['selector']['matchLabels']['app'])->toBe('zitadel-sso-example-com')
        ->and($deployment['spec']['template']['metadata']['labels']['larakube.io/tool'])->toBe('sso')
        ->and($deployment['spec']['template']['metadata']['labels']['larakube.io/component'])->toBe('zitadel')
        ->and($service['metadata']['name'])->toBe('zitadel-sso-example-com')
        ->and($service['spec']['selector']['app'])->toBe('zitadel-sso-example-com')
        ->and($ingress['metadata']['name'])->toBe('zitadel-sso-example-com')
        ->and($ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'])->toBe('zitadel-sso-example-com')
        ->and($env['ZITADEL_MASTERKEY']['valueFrom']['secretKeyRef']['name'])->toBe('zitadel-secrets-sso-example-com')
        ->and($env['ZITADEL_DATABASE_POSTGRES_DATABASE']['value'])->toBe('zitadel_sso_example_com')
        ->and($env['ZITADEL_DATABASE_POSTGRES_USER_USERNAME']['value'])->toBe('zitadel_sso_example_com')
        ->and($env['ZITADEL_DATABASE_POSTGRES_ADMIN_USERNAME']['value'])->toBe('zitadel_sso_example_com')
        ->and($env['ZITADEL_DATABASE_POSTGRES_USER_PASSWORD']['valueFrom']['secretKeyRef']['name'])->toBe('zitadel-secrets-sso-example-com');

    foreach ([$deployment, $service, $ingress] as $object) {
        expect($object['metadata']['labels']['larakube.io/instance'])->toBe('sso-example-com');
    }
});

test('a --no-plex install names its bundled Postgres, claim and Service from ToolInstance', function (): void {
    $documents = collect(zitadelDocuments(['noPlex' => true]));
    $database = $documents->first(fn ($d) => ($d['kind'] ?? null) === 'Deployment' && $d['metadata']['name'] === 'zitadel-db-sso-example-com');
    $claim = $documents->firstWhere('kind', 'PersistentVolumeClaim');
    $databaseService = $documents->first(fn ($d) => ($d['kind'] ?? null) === 'Service' && $d['metadata']['name'] === 'zitadel-db-sso-example-com');
    $zitadel = $documents->first(fn ($d) => ($d['kind'] ?? null) === 'Deployment' && $d['metadata']['name'] === 'zitadel-sso-example-com');
    $host = collect($zitadel['spec']['template']['spec']['initContainers'][0]['env'])->firstWhere('name', 'ZITADEL_DATABASE_POSTGRES_HOST');
    $env = collect($database['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');

    expect($database)->not->toBeNull()
        ->and($claim['metadata']['name'])->toBe('zitadel-db-storage-sso-example-com')
        ->and($database['spec']['template']['spec']['volumes'][0]['persistentVolumeClaim']['claimName'])->toBe('zitadel-db-storage-sso-example-com')
        ->and($databaseService)->not->toBeNull()
        ->and($host['value'])->toBe('zitadel-db-sso-example-com')
        ->and($env['POSTGRES_DB']['value'])->toBe('zitadel_sso_example_com')
        ->and($env['POSTGRES_USER']['value'])->toBe('zitadel_sso_example_com');
});

test('the manifest derives the instance from the host when none is passed', function (): void {
    $deployment = collect(zitadelDocuments(['instance' => '']))->firstWhere('kind', 'Deployment');

    expect($deployment['metadata']['name'])->toBe('zitadel-sso-example-com');
});

test('the Ingress renders from the host alone, the way the local re-point does', function (): void {
    $rendered = view('k8s.sso.ingress', ['host' => 'sso.example.com'])->render();

    expect($rendered)->toContain('name: zitadel-sso-example-com')
        ->and($rendered)->toContain('larakube.io/instance: sso-example-com');
});

test('the shared proxy names its Deployment, Service, Ingress and Secret from the Zitadel instance', function (): void {
    $rendered = view('k8s.sso.proxy', [
        'names' => ToolInstance::forInstance(ClusterTool::SSO, 'sso-example-com'),
        'namespace' => 'larakube-shared', 'authHost' => 'auth.example.com', 'ssoHost' => 'sso.example.com',
        'isLocal' => false, 'proxied' => false, 'clientId' => 'cid', 'clientSecret' => 'csecret',
        'cookieDomain' => '.example.com', 'cookieSecret' => 'cookiesecret', 'rbacRole' => null, 'secretChecksum' => 'abc',
    ])->render();
    $documents = collect(array_map(fn (string $doc) => Yaml::parse($doc), array_values(array_filter(array_map('trim', preg_split('/^---$/m', $rendered)), fn (string $doc) => $doc !== ''))));
    $deployment = $documents->firstWhere('kind', 'Deployment');

    expect($documents->firstWhere('kind', 'Secret')['metadata']['name'])->toBe('proxy-secrets-sso-example-com')
        ->and($deployment['metadata']['name'])->toBe('proxy-sso-example-com')
        ->and($deployment['spec']['template']['spec']['containers'][0]['envFrom'][0]['secretRef']['name'])->toBe('proxy-secrets-sso-example-com')
        ->and($documents->firstWhere('kind', 'Service')['metadata']['name'])->toBe('proxy-sso-example-com')
        ->and($documents->firstWhere('kind', 'Ingress')['metadata']['name'])->toBe('proxy-sso-example-com');
});

test('teardown names every resource the manifests declare', function (): void {
    $components = collect(ClusterTool::SSO->components('sso-luchtech-dev'))->keyBy('key');
    $refs = fn (string $key) => collect($components[$key]->resources)->map(fn ($r) => "{$r['kind']}/{$r['name']}")->all();

    expect($components['zitadel']->deployment)->toBe('zitadel-sso-luchtech-dev')
        ->and($refs('zitadel'))->toBe([
            'service/zitadel-sso-luchtech-dev',
            'ingress/zitadel-sso-luchtech-dev',
            'secret/zitadel-secrets-sso-luchtech-dev',
        ])
        ->and($components['db']->deployment)->toBe('zitadel-db-sso-luchtech-dev')
        ->and($refs('db'))->toBe([
            'service/zitadel-db-sso-luchtech-dev',
            'pvc/zitadel-db-storage-sso-luchtech-dev',
        ])
        ->and($components['proxy']->deployment)->toBe('proxy-sso-luchtech-dev');
});
