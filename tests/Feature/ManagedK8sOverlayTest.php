<?php

use App\Data\ConfigData;
use App\Enums\CacheDriver;

/**
 * Managed-K8s overlay compatibility: optional per-env knobs that let a
 * generated overlay drop into a managed cluster (EKS/GKE/AKS). Every knob
 * must no-op to today's Single-Node-Hero output when unset — the snapshot
 * suite is the regression guard; these tests cover the override behavior.
 */
function eksConfig(array $envOverrides): ConfigData
{
    return ConfigData::from([
        'name' => 'eksapp',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'environments' => [
            'local' => [],
            'production' => array_merge(['hosts' => ['web' => 'eksapp.com']], $envOverrides),
        ],
    ]);
}

// --- Resolution getters (defaults mirror today's behavior) ---

test('overlay knobs default to current Single-Node-Hero behavior', function (): void {
    $config = new ConfigData(name: 'plain');

    expect($config->getNamespace('production'))->toBe('plain-production')
        ->and($config->getServiceAccount('production'))->toBeNull()
        ->and($config->getServiceAccountAnnotations('production'))->toBeEmpty()
        ->and($config->getImagePullSecret('production'))->toBe('ghcr-login')
        ->and($config->getIngressAnnotations('production'))->toBeEmpty();
});

test('namespace override replaces the derived {name}-{env}', function (): void {
    $config = eksConfig(['namespace' => 'eksapp']);

    expect($config->getNamespace('production'))->toBe('eksapp')
        // other envs still derive normally
        ->and($config->getNamespace('local'))->toBe('eksapp-local');
});

test('imagePullSecret can be overridden or omitted entirely', function (): void {
    expect(eksConfig(['imagePullSecret' => 'dockerhub'])->getImagePullSecret('production'))
        ->toBe('dockerhub')
        ->and(eksConfig(['omitImagePullSecret' => true])->getImagePullSecret('production'))->toBeNull();
});

// --- Phase 1: imagePullSecret in the manifests ---

// The web-only deployment-patch is a single YAML doc; the helper returns it
// un-listed (no '---'). Normalize to the first/only document.
function prodWebPatch(ConfigData $config): array
{
    $patch = generateManifestsAsArray($config)['overlays/production/deployment-patch.yaml'];

    return $patch[0] ?? $patch;
}

test('Phase 1: production deployment-patch uses the default ghcr-login pull secret', function (): void {
    $web = prodWebPatch(eksConfig([]));
    expect($web['spec']['template']['spec']['imagePullSecrets'][0]['name'])->toBe('ghcr-login');
});

test('Phase 1: omitImagePullSecret drops the imagePullSecrets block (ECR/IRSA)', function (): void {
    $web = prodWebPatch(eksConfig(['omitImagePullSecret' => true]));
    expect($web['spec']['template']['spec'])->not->toHaveKey('imagePullSecrets');
});

test('Phase 1: a custom pull secret name flows into the patch', function (): void {
    $web = prodWebPatch(eksConfig(['imagePullSecret' => 'ecr-creds']));
    expect($web['spec']['template']['spec']['imagePullSecrets'][0]['name'])->toBe('ecr-creds');
});

// --- Phase 2: namespace override in the manifests ---

test('Phase 2: namespace override lands the overlay in an existing namespace', function (): void {
    $manifests = generateManifestsAsArray(eksConfig(['namespace' => 'eksapp']));

    // Overlay kustomization sets the overridden namespace…
    expect($manifests['overlays/production/kustomization.yaml']['namespace'])->toBe('eksapp');

    // …and the Namespace object it creates matches.
    expect($manifests['overlays/production/namespace.yaml']['metadata']['name'])->toBe('eksapp');

    // Local overlay is untouched by a production-only override.
    expect($manifests['overlays/local/kustomization.yaml']['namespace'])->toBe('eksapp-local');
});

test('Phase 2: in-cluster service FQDNs follow the overridden namespace', function (): void {
    $config = eksConfig(['namespace' => 'eksapp']);
    $config->setCacheDriver(CacheDriver::REDIS);

    expect($config->getInternalFqdn(CacheDriver::REDIS, 'production'))
        ->toEndWith('.eksapp.svc.cluster.local');

    // Default-namespace env still resolves the derived namespace.
    expect($config->getInternalFqdn(CacheDriver::REDIS, 'staging'))
        ->toEndWith('.eksapp-staging.svc.cluster.local');
});

// --- Phase 3: ServiceAccount + IRSA ---

test('Phase 3: no ServiceAccount is emitted by default (matches today)', function (): void {
    $manifests = generateManifestsAsArray(eksConfig([]));

    expect($manifests)->not->toHaveKey('overlays/production/serviceaccount.yaml')
        ->and(prodWebPatch(eksConfig([]))['spec']['template']['spec'])->not->toHaveKey('serviceAccountName');
});

test('Phase 3: opting into a serviceAccount emits the SA + IRSA annotation and binds the pods', function (): void {
    $config = eksConfig([
        'serviceAccount' => 'eksapp-sa',
        'serviceAccountAnnotations' => ['eks.amazonaws.com/role-arn' => 'arn:aws:iam::123:role/eksapp'],
    ]);
    $manifests = generateManifestsAsArray($config);

    // ServiceAccount resource with the IRSA annotation.
    $sa = $manifests['overlays/production/serviceaccount.yaml'];
    expect($sa['kind'])->toBe('ServiceAccount')
        ->and($sa['metadata']['name'])->toBe('eksapp-sa')
        ->and($sa['metadata']['annotations']['eks.amazonaws.com/role-arn'])
        ->toBe('arn:aws:iam::123:role/eksapp');

    // Registered as an overlay resource.
    expect($manifests['overlays/production/kustomization.yaml']['resources'])
        ->toContain('serviceaccount.yaml');

    // App pods reference it.
    expect(prodWebPatch($config)['spec']['template']['spec']['serviceAccountName'])
        ->toBe('eksapp-sa');
});

// --- Phase 4: ingress annotation passthrough ---

test('Phase 4: ingress-patch carries only controller defaults when no extras set', function (): void {
    $ingress = generateManifestsAsArray(eksConfig([]))['overlays/production/ingress-patch.yaml'];
    $annotations = $ingress['metadata']['annotations'];

    expect($annotations)->toHaveKey('traefik.ingress.kubernetes.io/router.tls')
        ->and($annotations)->not->toHaveKey('alb.ingress.kubernetes.io/certificate-arn');
});

test('Phase 4: per-env annotations merge in, free-form JSON values survive as valid YAML', function (): void {
    $config = eksConfig(['ingressAnnotations' => [
        'alb.ingress.kubernetes.io/certificate-arn' => 'arn:aws:acm::123:certificate/abc',
        'alb.ingress.kubernetes.io/conditions.web' => '[{"field":"host-header","hostHeaderConfig":{"values":["eksapp.com"]}}]',
    ]]);

    $annotations = generateManifestsAsArray($config)['overlays/production/ingress-patch.yaml']['metadata']['annotations'];

    expect($annotations['alb.ingress.kubernetes.io/certificate-arn'])
        ->toBe('arn:aws:acm::123:certificate/abc')
        // round-trips through json_encode + YAML parse unchanged
        ->and($annotations['alb.ingress.kubernetes.io/conditions.web'])
        ->toBe('[{"field":"host-header","hostHeaderConfig":{"values":["eksapp.com"]}}]')
        // controller defaults still present
        ->and($annotations)->toHaveKey('traefik.ingress.kubernetes.io/router.tls');
});
