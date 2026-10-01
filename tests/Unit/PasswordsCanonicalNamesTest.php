<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ResourceNaming;
use App\Enums\SecretKind;
use Symfony\Component\Yaml\Yaml;

function vaultManifest(array $overrides = []): array
{
    $rendered = view('k8s.vault.shared', array_merge([
        'host' => 'vault.example.com',
        'instance' => 'vault-example-com',
        'adminToken' => 'plain',
        'hashedAdminToken' => 'hashed',
        'databaseUrl' => 'postgresql://x:y@postgres:5432/x',
        'isLocal' => false,
        'proxied' => false,
        'vpnOnly' => false,
        'volumeSize' => fn (string $claim, string $default): string => $default,
    ], $overrides))->render();

    return array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $rendered)), fn (string $doc) => $doc !== '')),
    );
}

test('Vaultwarden resolves every resource name from ToolInstance with the category gone', function (): void {
    $names = ToolInstance::forInstance(ClusterTool::PASSWORDS, 'vault-luchtech-dev');

    expect($names->deployment())->toBe('vaultwarden-vault-luchtech-dev')
        ->and($names->secret())->toBe('vaultwarden-secrets-vault-luchtech-dev')
        ->and($names->secret(SecretKind::OIDC))->toBe('vaultwarden-oidc-vault-luchtech-dev')
        ->and($names->secret(SecretKind::SMTP))->toBe('vaultwarden-smtp-vault-luchtech-dev')
        ->and($names->secret(SecretKind::SSO_APP))->toBe('vaultwarden-sso-vault-luchtech-dev')
        ->and($names->volume())->toBe('vaultwarden-storage-vault-luchtech-dev')
        ->and($names->database())->toBe('vaultwarden_vault_luchtech_dev')
        ->and($names->vpnMiddleware()->name)->toBe('vaultwarden-vpn-only-vault-luchtech-dev')
        ->and(ClusterTool::VAULTWARDEN->resourceNaming())->toBe(ResourceNaming::CANONICAL);
});

test('the rotation URL is built from the role OpenBao returns, so it is right for every instance', function (): void {
    $ref = ClusterTool::PASSWORDS->dbSecretRef('vault-luchtech-dev');

    expect($ref['secret'])->toBe('vaultwarden-secrets-vault-luchtech-dev')
        ->and($ref['key'])->toBe('VAULTWARDEN_DATABASE_URL')
        ->and($ref['template'])->toBe('postgresql://{{ .username }}:{{ .password }}@postgres.larakube-plex.svc.cluster.local:5432/{{ .username }}');
});

/**
 * Backup discovery resolves a Deployment to a component by its name, so a
 * Deployment that carries no instance matches nothing and its volume was never
 * archived. The vault's /data holds attachments, sends and the signing key.
 */
test('the canonical Deployment name resolves to a component whose volume is backed up', function (): void {
    $hit = ClusterTool::forInstancedDeployment('vaultwarden-vault-luchtech-dev');

    expect($hit)->not->toBeNull()
        ->and($hit['instance'])->toBe('vault-luchtech-dev')
        ->and($hit['component']->backupVolume)->toBeTrue()
        ->and($hit['component']->backupPaths)->toBe(['/data'])
        ->and(ClusterTool::forInstancedDeployment('vaultwarden'))->toBeNull();
});

test('the manifest names every object from ToolInstance and the claim matches the mount', function (): void {
    $documents = collect(vaultManifest());
    $secret = $documents->firstWhere('kind', 'Secret');
    $pvc = $documents->firstWhere('kind', 'PersistentVolumeClaim');
    $deployment = $documents->firstWhere('kind', 'Deployment');
    $service = $documents->firstWhere('kind', 'Service');
    $ingress = $documents->firstWhere('kind', 'Ingress');
    $env = collect($deployment['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');

    expect($secret['metadata']['name'])->toBe('vaultwarden-secrets-vault-example-com')
        ->and($pvc['metadata']['name'])->toBe('vaultwarden-storage-vault-example-com')
        ->and($deployment['metadata']['name'])->toBe('vaultwarden-vault-example-com')
        ->and($deployment['spec']['template']['spec']['volumes'][0]['persistentVolumeClaim']['claimName'])->toBe('vaultwarden-storage-vault-example-com')
        ->and($env['ADMIN_TOKEN']['valueFrom']['secretKeyRef']['name'])->toBe('vaultwarden-secrets-vault-example-com')
        ->and($env['DATABASE_URL']['valueFrom']['secretKeyRef']['name'])->toBe('vaultwarden-secrets-vault-example-com')
        ->and($service['metadata']['name'])->toBe('vaultwarden-vault-example-com')
        ->and($service['spec']['selector']['app'])->toBe('vaultwarden-vault-example-com')
        ->and($ingress['metadata']['name'])->toBe('vaultwarden-vault-example-com')
        ->and($ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'])->toBe('vaultwarden-vault-example-com');

    foreach ([$secret, $pvc, $deployment, $service, $ingress] as $object) {
        expect($object['metadata']['labels']['larakube.io/tool'])->toBe('passwords')
            ->and($object['metadata']['labels']['larakube.io/instance'])->toBe('vault-example-com');
    }
});

test('--vpn-only attaches the Middleware named for its instance', function (): void {
    $ingress = collect(vaultManifest(['vpnOnly' => true]))->firstWhere('kind', 'Ingress');

    expect($ingress['metadata']['annotations']['traefik.ingress.kubernetes.io/router.middlewares'])
        ->toBe('larakube-vault-vaultwarden-vpn-only-vault-example-com@kubernetescrd');
});

test('the Ingress renders from the host alone, the way the shared-service reconcile does', function (): void {
    $rendered = view('k8s.vault.ingress', ['host' => 'vault.example.com'])->render();

    expect($rendered)->toContain('name: vaultwarden-vault-example-com')
        ->and($rendered)->toContain('larakube.io/instance: vault-example-com');
});

test('teardown names every resource the manifest declares', function (): void {
    $component = ClusterTool::PASSWORDS->components('vault-luchtech-dev')[0];
    $refs = collect($component->resources)->map(fn ($r) => "{$r['kind']}/{$r['name']}")->all();

    expect($component->deployment)->toBe('vaultwarden-vault-luchtech-dev')
        ->and($refs)->toBe([
            'service/vaultwarden-vault-luchtech-dev',
            'ingress/vaultwarden-vault-luchtech-dev',
            'secret/vaultwarden-secrets-vault-luchtech-dev',
            'secret/vaultwarden-oidc-vault-luchtech-dev',
            'secret/vaultwarden-smtp-vault-luchtech-dev',
            'pvc/vaultwarden-storage-vault-luchtech-dev',
        ]);
});
