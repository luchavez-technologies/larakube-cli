<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ResourceNaming;
use App\Enums\SecretKind;
use Symfony\Component\Yaml\Yaml;

function stalwartDocuments(array $overrides = []): array
{
    $rendered = view('k8s.mail.stalwart', array_merge([
        'host' => 'send.example.com',
        'instance' => 'send-example-com',
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => false,
        'hostPort' => true,
        'volumeSize' => fn (string $claim, string $default): string => $default,
    ], $overrides))->render();

    return array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $rendered)), fn (string $doc) => $doc !== '')),
    );
}

test('Stalwart resolves every resource name from ToolInstance with the category gone', function (): void {
    $names = ToolInstance::forInstance(ClusterTool::MAIL, 'send-luchtech-dev');

    expect($names->deployment())->toBe('stalwart-send-luchtech-dev')
        ->and($names->name('mail'))->toBe('stalwart-mail-send-luchtech-dev')
        ->and($names->secret())->toBe('stalwart-secrets-send-luchtech-dev')
        ->and($names->secret(SecretKind::STORE))->toBe('stalwart-store-send-luchtech-dev')
        ->and($names->name('sender'))->toBe('stalwart-sender-send-luchtech-dev')
        ->and($names->name('relay'))->toBe('stalwart-relay-send-luchtech-dev')
        ->and($names->name('openbao'))->toBe('stalwart-openbao-send-luchtech-dev')
        ->and($names->configMap('config'))->toBe('stalwart-config-send-luchtech-dev')
        ->and($names->volume())->toBe('stalwart-storage-send-luchtech-dev')
        ->and($names->database())->toBe('stalwart_send_luchtech_dev')
        ->and($names->bucket())->toBe('stalwart-storage-send-luchtech-dev')
        ->and(ClusterTool::STALWART->resourceNaming())->toBe(ResourceNaming::CANONICAL);
});

test('the Redis tenant is the database name, so one install owns one registry row', function (): void {
    $names = ToolInstance::forInstance(ClusterTool::MAIL, 'send-luchtech-dev');

    expect($names->redisTenant())->toBe($names->database());
});

test('the rotation Secret and the OpenBao sync Secret are the store Secret the manifest reads', function (): void {
    expect(ClusterTool::MAIL->dbSecretRef('send-luchtech-dev')['secret'])->toBe('stalwart-store-send-luchtech-dev')
        ->and(ClusterTool::MAIL->openbaoSyncConfig('send-luchtech-dev')['secret'])->toBe('stalwart-store-send-luchtech-dev')
        ->and(ClusterTool::MAIL->openbaoSyncConfig('send-luchtech-dev'))->not->toHaveKey('kind');
});

/**
 * Backup discovery resolves a Deployment to a component by name, so the volume
 * is only archived while the name resolves.
 */
test('the canonical Deployment name resolves to a component whose volume is backed up', function (): void {
    $hit = ClusterTool::forInstancedDeployment('stalwart-send-luchtech-dev');

    expect($hit)->not->toBeNull()
        ->and($hit['instance'])->toBe('send-luchtech-dev')
        ->and($hit['component']->backupVolume)->toBeTrue()
        ->and($hit['component']->container)->toBe('stalwart');
});

test('the manifest names every object from ToolInstance and the claim matches the mount', function (): void {
    $documents = collect(stalwartDocuments());
    $deployment = $documents->firstWhere('kind', 'Deployment');
    $pvc = $documents->firstWhere('kind', 'PersistentVolumeClaim');
    $services = $documents->where('kind', 'Service')->values();
    $ingress = $documents->firstWhere('kind', 'Ingress');
    $env = collect($deployment['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');

    expect($deployment['metadata']['name'])->toBe('stalwart-send-example-com')
        ->and($deployment['spec']['selector']['matchLabels']['app'])->toBe('stalwart-send-example-com')
        ->and($deployment['spec']['template']['metadata']['labels']['larakube.io/tool'])->toBe('mail')
        ->and($pvc['metadata']['name'])->toBe('stalwart-storage-send-example-com')
        ->and(collect($deployment['spec']['template']['spec']['volumes'])->firstWhere('name', 'stalwart-data')['persistentVolumeClaim']['claimName'])->toBe('stalwart-storage-send-example-com')
        ->and($env['STALWART_RECOVERY_ADMIN']['valueFrom']['secretKeyRef']['name'])->toBe('stalwart-secrets-send-example-com')
        ->and($env['STALWART_STORE_PASSWORD']['valueFrom']['secretKeyRef']['name'])->toBe('stalwart-store-send-example-com')
        ->and($services->pluck('metadata.name')->all())->toBe(['stalwart-send-example-com', 'stalwart-mail-send-example-com'])
        ->and($services->pluck('spec.selector.app')->unique()->all())->toBe(['stalwart-send-example-com'])
        ->and($ingress['metadata']['name'])->toBe('stalwart-send-example-com')
        ->and($ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'])->toBe('stalwart-send-example-com');

    foreach ([$deployment, $pvc, $ingress, ...$services->all()] as $object) {
        expect($object['metadata']['labels']['larakube.io/instance'])->toBe('send-example-com');
    }
});

test('the Ingress renders from the host alone, the way the local re-point does', function (): void {
    $rendered = view('k8s.mail.ingress', ['host' => 'send.example.com'])->render();

    expect($rendered)->toContain('name: stalwart-send-example-com')
        ->and($rendered)->toContain('larakube.io/instance: send-example-com');
});

test('teardown names every resource the manifest declares', function (): void {
    $component = ClusterTool::MAIL->components('send-luchtech-dev')[0];
    $refs = collect($component->resources)->map(fn ($r) => "{$r['kind']}/{$r['name']}")->all();

    expect($component->deployment)->toBe('stalwart-send-luchtech-dev')
        ->and($refs)->toBe([
            'service/stalwart-send-luchtech-dev',
            'service/stalwart-mail-send-luchtech-dev',
            'ingress/stalwart-send-luchtech-dev',
            'configmap/stalwart-config-send-luchtech-dev',
            'secret/stalwart-secrets-send-luchtech-dev',
            'secret/stalwart-store-send-luchtech-dev',
            'secret/stalwart-sender-send-luchtech-dev',
            'secret/stalwart-relay-send-luchtech-dev',
            'pvc/stalwart-storage-send-luchtech-dev',
        ]);
});
