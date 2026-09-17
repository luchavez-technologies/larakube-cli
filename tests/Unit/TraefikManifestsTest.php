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
