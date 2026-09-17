<?php

use App\Data\ConfigData;
use Symfony\Component\Yaml\Yaml;

/**
 * Every cluster type renders Traefik from its own template, so a version bump
 * or RBAC change has to land in all of them at once.
 */
function traefikManifestsRender(string $view): array
{
    $rendered = view($view, match ($view) {
        'k8s.traefik-cloud' => ['email' => 'ops@example.com', 'ip' => '203.0.113.10'],
        'k8s.traefik-managed' => ['email' => 'ops@example.com', 'loadBalancerName' => 'lb-example'],
        default => [],
    })->render();

    return array_values(array_filter(array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_filter(preg_split('/^---\s*$/m', $rendered), fn (string $doc) => trim($doc) !== ''),
    )));
}

test('Traefik runs the pinned image and can read what its providers watch', function (string $view): void {
    $documents = collect(traefikManifestsRender($view));

    $deployment = $documents->first(fn ($d) => ($d['kind'] ?? null) === 'Deployment' && $d['metadata']['name'] === 'traefik');
    $role = $documents->first(fn ($d) => ($d['kind'] ?? null) === 'ClusterRole');
    $coreResources = collect($role['rules'])->first(fn ($rule) => $rule['apiGroups'] === [''])['resources'];

    expect($deployment['spec']['template']['spec']['containers'][0]['image'])->toBe(ConfigData::TRAEFIK_IMAGE)
        ->and($coreResources)->toContain('services', 'secrets', 'configmaps');
})->with(['k8s.traefik-cloud', 'k8s.traefik-managed', 'k8s.traefik-install']);

test('the Traefik image is pinned to an exact release, never a floating minor tag', function (): void {
    expect(ConfigData::TRAEFIK_IMAGE)->toMatch('/^traefik:v\d+\.\d+\.\d+$/');
});

test('the DNS challenge swaps the HTTP challenge for Cloudflare and injects the token from the Traefik Secret', function (string $view): void {
    $rendered = view($view, [
        'email' => 'ops@example.com',
        'ip' => '203.0.113.10',
        'loadBalancerName' => 'lb-example',
        'dnsChallenge' => true,
    ])->render();

    $container = collect(Yaml::parse(
        collect(preg_split('/^---\s*$/m', $rendered))->first(fn ($doc) => str_contains($doc, 'kind: Deployment')),
    )['spec']['template']['spec']['containers'])->first();

    expect($container['args'])
        ->toContain('--certificatesresolvers.letsencrypt.acme.dnschallenge.provider=cloudflare')
        ->not->toContain('--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web')
        ->and($container['env'])->toBe([[
            'name' => 'CF_DNS_API_TOKEN',
            'valueFrom' => ['secretKeyRef' => ['name' => 'traefik-acme-cloudflare', 'key' => 'token']],
        ]]);
})->with(['k8s.traefik-cloud', 'k8s.traefik-managed']);

test('the HTTP challenge stays the default and carries no Cloudflare token', function (): void {
    $rendered = view('k8s.traefik-cloud', ['email' => 'ops@example.com', 'ip' => '203.0.113.10'])->render();

    expect($rendered)
        ->toContain('--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web')
        ->not->toContain('dnschallenge')
        ->not->toContain('CF_DNS_API_TOKEN');
});
