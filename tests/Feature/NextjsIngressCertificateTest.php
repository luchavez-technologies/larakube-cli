<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Enums\AppFramework;
use App\Enums\DeploymentStrategy;

/**
 * A deployed Next.js app gets a real certificate the same way a Laravel app
 * does. Its Ingress used to carry no resolver at all, so Traefik served its
 * default self-signed certificate.
 */
function nextjsIngress(string $environment, array $env = [], DeploymentStrategy $strategy = DeploymentStrategy::SINGLE_NODE): string
{
    $config = new ConfigData(name: 'shop');
    $config->framework = AppFramework::NEXTJS;
    $config->strategy = $strategy;
    $config->environments[$environment] = EnvironmentData::from($env);

    return view('k8s.nextjs.ingress', [
        'config' => $config,
        'environment' => $environment,
        'resourceName' => 'shop-nextjs',
        'hosts' => ['shop.example.com'],
    ])->render();
}

test('a single-node cloud environment asks Traefik for a Let\'s Encrypt certificate', function (): void {
    expect(nextjsIngress('production'))->toContain('router.tls.certresolver: letsencrypt');
});

test('an environment with a cert-manager issuer uses it', function (): void {
    expect(nextjsIngress('production', ['certManagerIssuer' => 'letsencrypt-prod'], DeploymentStrategy::MULTI_NODE_HA))
        ->toContain('cert-manager.io/cluster-issuer: letsencrypt-prod')
        ->not->toContain('certresolver');
});

test('local and preview use the LaraKube Local CA, and offline environments can\'t reach Let\'s Encrypt', function (): void {
    expect(nextjsIngress('local'))->not->toContain('certresolver')
        ->and(nextjsIngress('production', ['offline' => true]))->not->toContain('certresolver');
});
