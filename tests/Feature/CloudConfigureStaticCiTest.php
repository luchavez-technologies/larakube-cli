<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Yaml\Yaml;

/** The view data configureGha() hands the static template, with one site's settings. */
function staticCiViewData(array $audit = [], string $publicEnvScript = ''): array
{
    $config = new ConfigData(name: 'portal');
    $config->framework = AppFramework::ASTRO;

    return [
        'config' => $config,
        'environment' => 'production',
        'branch' => 'main',
        'appName' => 'portal',
        'namespace' => 'portal-production',
        'podName' => 'web',
        'upperEnv' => 'PRODUCTION',
        'vpnHost' => null,
        'publicEnvScript' => $publicEnvScript,
        'secrets' => [
            'k_env' => '${{ secrets.PRODUCTION_KUBECONFIG }}',
            'k_base' => '${{ secrets.KUBECONFIG }}',
            'vpn_key' => '${{ secrets.PRODUCTION_NETBIRD_SETUP_KEY }}',
        ],
        'gha' => [
            'actor' => '${{ github.actor }}',
            'token' => '${{ secrets.GITHUB_TOKEN }}',
            'registry_provider' => 'forgejo',
            'registry_host' => 'git.example.com',
            'image_name' => 'acme/portal',
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

function staticCiRunner(): object
{
    return new class
    {
        use ConfiguresCloudEnvironment, GeneratesProjectInfrastructure, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

        public function staticEnv(ConfigData $config, string $environment): string
        {
            return $this->buildStaticPublicEnvScript($config, $environment);
        }
    };
}

test('a static workflow builds Dockerfile.static with no PHP, no build target, and no Laravel secrets', function (): void {
    $workflow = view('k8s.cloud-pilot-deploy-static', staticCiViewData())->render();

    expect($workflow)
        ->toContain('--file Dockerfile.static')
        ->toContain('--secret id=dotenv,src=.env')
        ->toContain('npm audit --audit-level=critical')
        ->not->toContain('--target')
        ->not->toContain('setup-php')
        ->not->toContain('composer')
        ->not->toContain('artisan')
        ->not->toContain('laravel-secrets')
        ->not->toContain('laravel-config')
        ->toContain('kubectl rollout status deployment/web -n portal-production')
        ->toContain('s|image: portal:production-latest|image: ${{ needs.build.outputs.image_ref }}|g');
});

test('every static audit combination renders valid YAML with a build and a deploy job', function (): void {
    foreach ([[], ['skip' => true, 'gitleaks' => false, 'semgrep' => false, 'dependencyAudit' => false, 'trivy' => false], ['trivy' => false]] as $audit) {
        $parsed = Yaml::parse(view('k8s.cloud-pilot-deploy-static', staticCiViewData($audit, "echo 'PUBLIC_API=https://api.example.com' >> .env"))->render());

        expect(array_keys($parsed['jobs']))->toBe(['build', 'deploy'])
            ->and($parsed['jobs']['build']['outputs']['image_ref'])->toBe('${{ steps.push.outputs.ref }}');
    }
});

test('a static workflow logs in to a non-GHCR registry with the uploaded registry secrets', function (): void {
    $workflow = view('k8s.cloud-pilot-deploy-static', staticCiViewData())->render();

    expect($workflow)
        ->toContain('REGISTRY_HOST: git.example.com')
        ->toContain('IMAGE_NAME: acme/portal')
        ->toContain('REGISTRY_USER: ${{ secrets.PRODUCTION_REGISTRY_USERNAME }}');
});

test('only the variables the framework compiles into the browser bundle reach the workflow', function (): void {
    $directory = TemporaryDirectory::make();
    file_put_contents($directory->path('.env.production'), implode("\n", [
        'PUBLIC_POCKETBASE_URL="https://data.example.com"',
        "PUBLIC_SITE_NAME='Portal'",
        'VITE_IGNORED=1',
        'DB_PASSWORD=never-in-ci',
        '# PUBLIC_COMMENTED=1',
    ]));

    $astro = new ConfigData(name: 'portal', path: $directory->path());
    $astro->framework = AppFramework::ASTRO;
    $docs = new ConfigData(name: 'docs', path: $directory->path());
    $docs->framework = AppFramework::DOCUSAURUS;

    $script = staticCiRunner()->staticEnv($astro, 'production');

    expect($script)
        ->toContain("echo 'PUBLIC_POCKETBASE_URL=https://data.example.com' >> .env")
        ->toContain("echo 'PUBLIC_SITE_NAME=Portal' >> .env")
        ->not->toContain('VITE_IGNORED')
        ->not->toContain('DB_PASSWORD')
        ->not->toContain('PUBLIC_COMMENTED')
        ->and(staticCiRunner()->staticEnv($docs, 'production'))->toBe('')
        ->and(staticCiRunner()->staticEnv($astro, 'staging'))->toBe('');

    $directory->delete();
});

test('a static GitLab pipeline builds Dockerfile.static and skips the Laravel runtime steps', function (): void {
    $config = new ConfigData(name: 'portal');
    $config->framework = AppFramework::ASTRO;

    $pipeline = view('k8s.cloud-pilot-deploy-gitlab', [
        'config' => $config,
        'appName' => 'portal',
        'podName' => 'web',
        'cloudEnvs' => ['production' => [
            'branch' => 'main',
            'upperName' => 'PRODUCTION',
            'namespace' => 'portal-production',
            'registry' => 'gitlab',
            'registry_host' => '$CI_REGISTRY',
            'imageLatest' => '$CI_REGISTRY/$CI_PROJECT_PATH:latest',
            'imageSha' => '$CI_REGISTRY/$CI_PROJECT_PATH:$CI_COMMIT_SHA',
            'static' => true,
            'publicEnvScript' => '',
        ]],
    ])->render();

    expect($pipeline)
        ->toContain('--file Dockerfile.static')
        ->not->toContain('--target deploy')
        ->not->toContain('laravel-secrets')
        ->not->toContain('laravel-config')
        ->toContain('kubectl rollout status deployment/web');
});

test('a registry-pushed static site pulls with the registry secret, a sideloaded one needs none', function (): void {
    $render = function (array $environment): array {
        $config = ConfigData::from(['name' => 'portal', 'framework' => 'astro']);
        $config->environments['production'] = App\Data\EnvironmentData::from($environment);

        $documents = collect(preg_split('/^---$/m', view('k8s.static.caddy', [
            'config' => $config,
            'environment' => 'production',
            'hosts' => ['portal.example.com'],
            'proxied' => false,
        ])->render()))->map(fn (string $doc) => trim($doc))->filter()->map(fn (string $doc) => Yaml::parse($doc));

        return $documents->firstWhere('kind', 'Deployment')['spec']['template']['spec'];
    };

    expect($render(['registry' => ['provider' => 'forgejo', 'image' => 'acme/portal', 'host' => 'git.example.com']])['imagePullSecrets'])
        ->toBe([['name' => 'forgejo-login']])
        ->and($render([]))->not->toHaveKey('imagePullSecrets');
});

test('Semgrep installs pip first when the job image lacks it', function (): void {
    $workflow = view('k8s.cloud-pilot-deploy-static', staticCiViewData())->render();

    $pip = strpos($workflow, 'python3 -m pip --version >/dev/null 2>&1 || {');
    $install = strpos($workflow, 'python3 -m pip install --quiet --break-system-packages semgrep');

    expect($pip)->not->toBeFalse()
        ->and($pip)->toBeLessThan($install)
        ->and($workflow)->toContain('apt-get install -y -qq --no-install-recommends python3-pip');
});

test('permissions are declared for GitHub, where GHCR needs them, and omitted for Forgejo, which rejects them', function (): void {
    $forgejo = staticCiViewData();
    $forgejo['gha']['forge'] = 'forgejo';
    $github = staticCiViewData();
    $github['gha']['forge'] = 'github';

    $forgejoJobs = Yaml::parse(view('k8s.cloud-pilot-deploy-static', $forgejo)->render())['jobs'];
    $githubJobs = Yaml::parse(view('k8s.cloud-pilot-deploy-static', $github)->render())['jobs'];

    expect($forgejoJobs['build'])->not->toHaveKey('permissions')
        ->and($forgejoJobs['deploy'])->not->toHaveKey('permissions')
        ->and($githubJobs['build']['permissions'])->toBe(['contents' => 'read', 'packages' => 'write'])
        ->and($githubJobs['deploy']['permissions'])->toBe(['contents' => 'read']);
});
