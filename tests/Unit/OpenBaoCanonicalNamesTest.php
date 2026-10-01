<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ResourceNaming;
use App\Enums\SecretKind;
use Symfony\Component\Yaml\Yaml;

/** @return list<array<string, mixed>> */
function openBaoDocuments(array $overrides = []): array
{
    $rendered = view('k8s.secrets.openbao', array_merge([
        'namespace' => 'larakube-secrets',
        'host' => 'secrets.example.com',
        'instance' => 'secrets-example-com',
        'autoUnseal' => true,
        'volumeSize' => fn (string $claim, string $default): string => $default,
    ], $overrides))->render();

    return array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $rendered)), fn (string $doc) => $doc !== '')),
    );
}

test('OpenBao resolves every resource name from ToolInstance with the category gone', function (): void {
    $names = ToolInstance::forInstance(ClusterTool::SECRETS, 'secrets-luchtech-dev');

    expect($names->deployment())->toBe('openbao-secrets-luchtech-dev')
        ->and($names->secret())->toBe('openbao-secrets-secrets-luchtech-dev')
        ->and($names->secret(SecretKind::OIDC))->toBe('openbao-oidc-secrets-luchtech-dev')
        ->and($names->configMap('config'))->toBe('openbao-config-secrets-luchtech-dev')
        ->and($names->volume())->toBe('openbao-storage-secrets-luchtech-dev')
        ->and($names->name('auth-delegator'))->toBe('openbao-auth-delegator-secrets-luchtech-dev')
        ->and($names->vpnMiddleware()->name)->toBe('openbao-vpn-only-secrets-luchtech-dev')
        ->and(ClusterTool::SECRETS->resourceNaming())->toBe(ResourceNaming::CANONICAL)
        ->and(ClusterTool::OPENBAO->resourceNaming())->toBe(ResourceNaming::CANONICAL);
});

test('the canonical Deployment name resolves to a component whose volume is backed up', function (): void {
    $hit = ClusterTool::forInstancedDeployment('openbao-secrets-luchtech-dev');

    expect($hit)->not->toBeNull()
        ->and($hit['instance'])->toBe('secrets-luchtech-dev')
        ->and($hit['component']->backupVolume)->toBeTrue()
        ->and($hit['component']->container)->toBe('openbao');
});

test('the SSO schema names the OpenBao Deployment and the OIDC Secret from ToolInstance', function (): void {
    $schema = ClusterTool::SECRETS->oidcEnv(instance: 'secrets-luchtech-dev');

    expect($schema['deployment'])->toBe('openbao-secrets-luchtech-dev')
        ->and($schema['secret'])->toBe('openbao-oidc-secrets-luchtech-dev');
});

test('the manifest names every object from ToolInstance and the claim matches the mount', function (): void {
    $documents = collect(openBaoDocuments());
    $deployment = $documents->firstWhere('kind', 'Deployment');
    $pvc = $documents->firstWhere('kind', 'PersistentVolumeClaim');
    $service = $documents->firstWhere('kind', 'Service');
    $ingress = $documents->firstWhere('kind', 'Ingress');
    $account = $documents->firstWhere('kind', 'ServiceAccount');
    $binding = $documents->firstWhere('kind', 'ClusterRoleBinding');
    $volumes = collect($deployment['spec']['template']['spec']['volumes'])->keyBy('name');

    expect($deployment['metadata']['name'])->toBe('openbao-secrets-example-com')
        ->and($deployment['spec']['selector']['matchLabels']['app'])->toBe('openbao-secrets-example-com')
        ->and($deployment['spec']['template']['metadata']['labels']['larakube.io/tool'])->toBe('secrets')
        ->and($deployment['spec']['template']['spec']['serviceAccountName'])->toBe('openbao-secrets-example-com')
        ->and($account['metadata']['name'])->toBe('openbao-secrets-example-com')
        ->and($binding['metadata']['name'])->toBe('openbao-auth-delegator-secrets-example-com')
        ->and($binding['subjects'][0]['name'])->toBe('openbao-secrets-example-com')
        ->and($pvc['metadata']['name'])->toBe('openbao-storage-secrets-example-com')
        ->and($volumes['data']['persistentVolumeClaim']['claimName'])->toBe('openbao-storage-secrets-example-com')
        ->and($volumes['config']['configMap']['name'])->toBe('openbao-config-secrets-example-com')
        ->and($volumes['bootstrap']['secret']['secretName'])->toBe('openbao-secrets-secrets-example-com')
        ->and($service['metadata']['name'])->toBe('openbao-secrets-example-com')
        ->and($service['spec']['selector']['app'])->toBe('openbao-secrets-example-com')
        ->and($ingress['metadata']['name'])->toBe('openbao-secrets-example-com')
        ->and($ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'])->toBe('openbao-secrets-example-com');

    foreach ([$deployment, $pvc, $service, $ingress, $account] as $object) {
        expect($object['metadata']['labels']['larakube.io/instance'])->toBe('secrets-example-com');
    }
});

test('the manifest derives the instance from the host when none is passed', function (): void {
    $deployment = collect(openBaoDocuments(['instance' => '']))->firstWhere('kind', 'Deployment');

    expect($deployment['metadata']['name'])->toBe('openbao-secrets-example-com');
});

test('the Ingress renders from the host alone, the way the local re-point does', function (): void {
    $rendered = view('k8s.secrets.ingress', ['host' => 'secrets.example.com'])->render();

    expect($rendered)->toContain('name: openbao-secrets-example-com')
        ->and($rendered)->toContain('larakube.io/instance: secrets-example-com');
});

test('the VPN-only Ingress points at the instance Middleware', function (): void {
    $rendered = view('k8s.secrets.ingress', ['host' => 'secrets.example.com', 'vpnOnly' => true])->render();

    expect($rendered)->toContain('larakube-secrets-openbao-vpn-only-secrets-example-com@kubernetescrd');
});

test('teardown names every resource the manifest declares', function (): void {
    $component = ClusterTool::SECRETS->components('secrets-luchtech-dev')[0];
    $refs = collect($component->resources)->map(fn ($r) => "{$r['kind']}/{$r['name']}")->all();

    expect($component->deployment)->toBe('openbao-secrets-luchtech-dev')
        ->and($refs)->toBe([
            'service/openbao-secrets-luchtech-dev',
            'ingress/openbao-secrets-luchtech-dev',
            'configmap/openbao-config-secrets-luchtech-dev',
            'secret/openbao-secrets-secrets-luchtech-dev',
            'secret/openbao-oidc-secrets-luchtech-dev',
            'pvc/openbao-storage-secrets-luchtech-dev',
            'serviceaccount/openbao-secrets-luchtech-dev',
            'clusterrolebinding/openbao-auth-delegator-secrets-luchtech-dev',
        ]);
});

test('every generator and store reaches OpenBao on the instance Service', function (): void {
    $kubectl = 'kubectl';
    openBaoRegistered();

    $command = new class
    {
        use App\Traits\InteractsWithSecrets;

        public function url(string $kubectl): ?string
        {
            return $this->openBaoServerUrl($kubectl);
        }
    };

    expect($command->url($kubectl))->toBe('http://openbao-secrets-example-com.larakube-secrets.svc.cluster.local:8200');
});
