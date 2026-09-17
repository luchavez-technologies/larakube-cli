<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use Symfony\Component\Yaml\Yaml;

/** View data configureGha() hands the server template, for a Next.js app. */
function serverCiViewData(array $audit = [], string $forge = 'forgejo'): array
{
    $config = new ConfigData(name: 'shop');
    $config->framework = AppFramework::NEXTJS;

    return [
        'config' => $config,
        'environment' => 'production',
        'branch' => 'main',
        'appName' => 'shop',
        'namespace' => 'shop-production',
        'podName' => 'shop-nextjs',
        'upperEnv' => 'PRODUCTION',
        'vpnHost' => null,
        'publicEnvScript' => "echo 'NEXT_PUBLIC_API=https://api.example.com' >> .env",
        'secrets' => [
            'k_env' => '${{ secrets.PRODUCTION_KUBECONFIG }}',
            'k_base' => '${{ secrets.KUBECONFIG }}',
            'vpn_key' => '${{ secrets.PRODUCTION_NETBIRD_SETUP_KEY }}',
        ],
        'gha' => [
            'forge' => $forge,
            'actor' => '${{ github.actor }}',
            'token' => '${{ secrets.GITHUB_TOKEN }}',
            'registry_provider' => 'forgejo',
            'registry_host' => 'git.example.com',
            'image_name' => 'acme/shop',
            'k_data' => '${{ env.K_DATA }}',
            'registry_user' => '${{ secrets.PRODUCTION_REGISTRY_USERNAME }}',
            'registry_password' => '${{ secrets.PRODUCTION_REGISTRY_PASSWORD }}',
            'push_ref_output' => '${{ steps.push.outputs.ref }}',
            'image_ref' => '${{ needs.build.outputs.image_ref }}',
        ],
        'audit' => array_merge([
            'skip' => false,
            'strict' => false,
            'gitleaks' => true,
            'semgrep' => true,
            'dependencyAudit' => true,
            'trivy' => true,
            'withTests' => false,
            'failOn' => 'CRITICAL',
            'auditLevel' => 'critical',
        ], $audit),
    ];
}

test('a Next.js workflow builds its own Dockerfile and rolls out its own Deployment, with no PHP steps', function (): void {
    $workflow = view('k8s.cloud-pilot-deploy-server', serverCiViewData())->render();

    expect($workflow)
        ->toContain('--file Dockerfile.nextjs')
        ->toContain('--target deploy')
        ->toContain('npm audit --audit-level=critical')
        ->not->toContain('setup-php')
        ->not->toContain('composer')
        ->not->toContain('Dockerfile.php')
        ->not->toContain('laravel-secrets')
        ->toContain('kubectl rollout status deployment/shop-nextjs -n shop-production')
        ->toContain('s|image: shop:production-latest|image: ${{ needs.build.outputs.image_ref }}|g');
});

test('every Next.js workflow variant parses as YAML on both GitHub and Forgejo', function (): void {
    foreach (['github', 'forgejo'] as $forge) {
        foreach ([[], ['skip' => true, 'gitleaks' => false, 'semgrep' => false, 'dependencyAudit' => false, 'trivy' => false]] as $audit) {
            $jobs = Yaml::parse(view('k8s.cloud-pilot-deploy-server', serverCiViewData($audit, $forge))->render())['jobs'];

            expect(array_keys($jobs))->toBe(['build', 'deploy'])
                ->and(array_key_exists('permissions', $jobs['build']))->toBe($forge === 'github');
        }
    }
});

test('a Next.js GitLab pipeline builds Dockerfile.nextjs and skips Composer and the Laravel runtime steps', function (): void {
    $config = new ConfigData(name: 'shop');
    $config->framework = AppFramework::NEXTJS;

    $pipeline = view('k8s.cloud-pilot-deploy-gitlab', [
        'config' => $config,
        'appName' => 'shop',
        'podName' => 'shop-nextjs',
        'cloudEnvs' => ['production' => [
            'branch' => 'main',
            'upperName' => 'PRODUCTION',
            'namespace' => 'shop-production',
            'registry' => 'gitlab',
            'registry_host' => '$CI_REGISTRY',
            'imageLatest' => '$CI_REGISTRY/$CI_PROJECT_PATH:latest',
            'imageSha' => '$CI_REGISTRY/$CI_PROJECT_PATH:$CI_COMMIT_SHA',
            'static' => false,
            'php' => false,
            'dockerfile' => 'Dockerfile.nextjs',
            'target' => 'deploy',
            'publicEnvScript' => '',
        ]],
    ])->render();

    $parsed = Yaml::parse($pipeline);

    expect($pipeline)
        ->toContain('--file Dockerfile.nextjs')
        ->toContain('--target deploy')
        ->not->toContain('laravel-secrets')
        ->toContain('kubectl rollout status deployment/shop-nextjs')
        ->and($parsed)->not->toHaveKey('composer:production');
});

test('each framework names its own Dockerfile, build stage and Deployment', function (): void {
    expect(AppFramework::NEXTJS->dockerfile())->toBe('Dockerfile.nextjs')
        ->and(AppFramework::NEXTJS->buildTarget())->toBe('deploy')
        ->and(AppFramework::NEXTJS->workloadName('shop'))->toBe('shop-nextjs')
        ->and(AppFramework::ASTRO->dockerfile())->toBe('Dockerfile.static')
        ->and(AppFramework::ASTRO->buildTarget())->toBeNull()
        ->and(AppFramework::ASTRO->workloadName('shop'))->toBe('web')
        ->and(AppFramework::LARAVEL->dockerfile())->toBe('Dockerfile.php')
        ->and(AppFramework::LARAVEL->workloadName('shop'))->toBe('web')
        ->and(AppFramework::NEXTJS->usesNpm())->toBeTrue()
        ->and(AppFramework::LARAVEL->usesNpm())->toBeFalse();
});
