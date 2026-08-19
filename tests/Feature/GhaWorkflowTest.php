<?php

use App\Data\ConfigData;
use App\Enums\PackageManager;

/**
 * Helper: build the view-data array for the GHA workflow template.
 * Centralises the boilerplate so each test only overrides what it cares about.
 */
function ghaViewData(array $overrides = []): array
{
    $config = new ConfigData(name: 'test-app');
    $config->setPackageManager(PackageManager::NPM);
    $environment = 'production';
    $upperEnv = 'PRODUCTION';

    // Extract values that maps to separate top-level fields in the view
    $vpnHost = array_key_exists('vpnHost', $overrides) ? $overrides['vpnHost'] : null;
    $auditOverrides = array_key_exists('audit', $overrides) ? $overrides['audit'] : [];
    unset($overrides['vpnHost'], $overrides['audit']);

    // Mirrors the per-gate booleans ConfiguresCloudEnvironment passes in, which
    // already have the master `skip` folded into each of them.
    $audit = array_merge([
        'skip' => false,
        'strict' => false,
        'gitleaks' => true,
        'semgrep' => true,
        'dependencyAudit' => true,
        'trivy' => true,
        'withTests' => false,
        'failOn' => 'CRITICAL',
        'auditLevel' => 'critical',
    ], $auditOverrides);

    // `skip` is the master switch — ConfiguresCloudEnvironment folds it into
    // every per-gate boolean before handing them to the view, so a test that
    // sets only `skip` must see the same thing the command would produce.
    if ($audit['skip']) {
        foreach (['gitleaks', 'semgrep', 'dependencyAudit', 'trivy', 'withTests'] as $gate) {
            $audit[$gate] = false;
        }
    }

    $secrets = array_merge([
        'k_env' => '${{ secrets.'.$upperEnv.'_KUBECONFIG }}',
        'k_base' => '${{ secrets.KUBECONFIG }}',
        'vpn_key' => '${{ secrets.PRODUCTION_NETBIRD_SETUP_KEY }}',
    ], $overrides['secrets'] ?? []);
    unset($overrides['secrets']);

    $gha = array_merge([
        'repository' => '${{ github.repository }}',
        'actor' => '${{ github.actor }}',
        'token' => '${{ secrets.GITHUB_TOKEN }}',
        'sha' => '${{ github.sha }}',
        'registry_provider' => 'ghcr',
        'registry_host' => 'ghcr.io',
        'image_name' => '${{ github.repository }}',
        'k_data' => '${{ env.K_DATA }}',
        'image_latest' => '${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:latest',
        'image_sha' => '${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:${{ github.sha }}',
        'composer_cache_key' => "composer-\${{ hashFiles('composer.lock') }}",
        'registry_user' => '${{ secrets.'.$upperEnv.'_REGISTRY_USERNAME }}',
        'registry_password' => '${{ secrets.'.$upperEnv.'_REGISTRY_PASSWORD }}',
        'trivy_cache_key' => '${{ runner.os }}-trivy-db',
        'trivy_restore_key' => '${{ runner.os }}-trivy-',
    ], $overrides['gha'] ?? []);
    unset($overrides['gha']);

    // Mirrors ConfiguresCloudEnvironment::buildPublicEnvScript() — one literal
    // `echo 'KEY=VALUE' >> .env` line per public/build var, computed straight
    // from the blueprint. No .env content is ever a workflow secret.
    $publicEnv = $config->getAllPublicEnvironmentVariables($environment);
    $publicEnvScript = implode("\n          ", array_map(
        fn ($key, $value) => 'echo '.escapeshellarg("{$key}={$value}").' >> .env',
        array_keys($publicEnv),
        $publicEnv,
    ));

    return array_merge([
        'config' => $config,
        'environment' => $environment,
        'branch' => 'main',
        'appName' => 'test-app',
        'namespace' => 'test-app-production',
        'podName' => 'web',
        'upperEnv' => $upperEnv,
        'vpnHost' => $vpnHost,
        'publicEnvScript' => $publicEnvScript,
        'secrets' => $secrets,
        'gha' => $gha,
        'audit' => $audit,
    ], $overrides);
}

test('GHA workflow generation uses correct literal injection syntax', function (): void {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData())->render();

    // Verify Literal Injections
    expect($workflowContent)->toContain('FINAL_KUBE="${{ secrets.PRODUCTION_KUBECONFIG }}"');
    // No .env content is ever a workflow secret — public/build vars are baked
    // as literal `echo` lines instead (see the dedicated test below).
    expect($workflowContent)
        ->not->toContain('ENV_FILE_BASE64')
        ->not->toContain('FINAL_ENV');
    expect($workflowContent)->toContain('REGISTRY_HOST: ghcr.io')
        ->toContain('IMAGE_NAME: ${{ github.repository }}')
        ->toContain('REGISTRY_PROVIDER: ghcr')
        ->toContain('kubeconfig: ${{ env.K_DATA }}');

    // The runner uses a namespace-scoped credential, so the apply must strip the
    // cluster-scoped Namespace doc (the scoped SA can't apply it).
    expect($workflowContent)
        ->toContain('drop=1')
        ->toContain('kind:[ \\t]+Namespace');

    // The image tags must render as real GitHub expressions — NOT mangled into
    // compiled Blade (the bug: literal {{ }} inside {!! '…' !!} gets post-processed)
    expect($workflowContent)
        ->not->toContain('<?php')      // no compiled-Blade leakage
        ->not->toContain('echo e(');

    // Verify no Blade '@' symbols leaked into the GitHub syntax
    expect($workflowContent)->not->toContain('@{{');

    // Verify no unresolved variable placeholders
    expect($workflowContent)->not->toContain('{{ $upperEnv }}');
});

test('GHA workflow with default audit config emits security gates and split build', function (): void {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData())->render();

    // Phase 1 — Audit gates are present. Gitleaks runs as the MIT-licensed
    // binary, not gitleaks/gitleaks-action, which demands a paid licence on
    // organisation-owned repos and fails the build without one.
    expect($workflowContent)
        ->toContain('ghcr.io/gitleaks/gitleaks')
        ->toContain('detect --source=/repo')
        // The name still appears in an explanatory comment, so pin the thing
        // that actually matters: it is never invoked as an action.
        ->not->toContain('uses: gitleaks/gitleaks-action')
        ->toContain('composer audit')
        ->toContain('npm audit --audit-level=critical')
        ->toContain('semgrep scan --config=auto --severity=ERROR --error')
        ->toContain("scan-type: 'fs'")
        ->toContain('fetch-depth: 0');

    // Phase 2+3 — Build is split: load locally, scan, then push
    expect($workflowContent)
        ->toContain('load: true')
        ->toContain('push: false')
        ->toContain('Trivy image scan')
        ->toContain("severity: 'CRITICAL'")
        ->toContain('Push verified image');

    // Job name reflects audit is active
    expect($workflowContent)->toContain('Audit, Build & Push');
});

test('GHA workflow with --skip-audit produces the lean pipeline without gates', function (): void {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData([
        'audit' => ['skip' => true],
    ]))->render();

    // No audit steps
    expect($workflowContent)
        ->not->toContain('gitleaks')
        ->not->toContain('semgrep')
        ->not->toContain('Trivy')
        ->not->toContain('composer audit')
        ->not->toContain('npm audit');

    // No fetch-depth: 0
    expect($workflowContent)->not->toContain('fetch-depth');

    // No split build — a single push: true step
    expect($workflowContent)
        ->not->toContain('load: true')
        ->not->toContain('push: false')
        ->not->toContain('Push verified image');

    // Direct push
    expect($workflowContent)->toContain('push: true');

    // Job name is the lean version
    expect($workflowContent)
        ->toContain('Build & Push')
        ->not->toContain('Audit, Build & Push');
});

test('a single gate can be dropped without losing the rest of the audit', function (): void {
    // The point of the per-gate switches: Gitleaks' Action needs a paid licence
    // on org repos, and before this the only escape was --skip-audit, which
    // also threw away dependency auditing, SAST, Trivy and the tests.
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData([
        'audit' => ['gitleaks' => false],
    ]))->render();

    expect($workflowContent)
        ->not->toContain('gitleaks')
        // fetch-depth: 0 exists only so Gitleaks can scan history — it goes too.
        ->not->toContain('fetch-depth');

    // Everything else survives.
    expect($workflowContent)
        ->toContain('composer audit')
        ->toContain('semgrep scan')
        ->toContain('Trivy image scan')
        ->toContain('Audit, Build & Push');
});

test('each remaining gate can be dropped on its own', function (): void {
    $render = fn (string $gate) => view('k8s.cloud-pilot-deploy', ghaViewData([
        'audit' => [$gate => false],
    ]))->render();

    expect($render('semgrep'))
        ->not->toContain('semgrep scan')
        ->toContain('composer audit')
        ->toContain('Trivy image scan')
        ->and($render('dependencyAudit'))->not->toContain('composer audit')->not->toContain('npm audit')
        ->toContain('semgrep scan')
        ->and($render('trivy'))->not->toContain('Trivy')
        ->toContain('semgrep scan')
        ->toContain('composer audit');
});

test('dropping Trivy collapses the split build instead of building twice', function (): void {
    // The load/scan/push split exists only to scan the artifact before
    // publishing it. With no image scan the middle step is dead weight, so it
    // must collapse to one build-and-push rather than building the image twice.
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData([
        'audit' => ['trivy' => false],
    ]))->render();

    expect($workflowContent)
        ->toContain('Build and push application image')
        ->not->toContain('load, do not push')
        ->not->toContain('load: true')
        ->not->toContain('push: false')
        ->not->toContain('Push verified image');

    // Still one push of both tags.
    expect(substr_count($workflowContent, 'push: true'))->toBe(1);
});

test('GHA workflow with --strict uses CRITICAL,HIGH severity', function (): void {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData([
        'audit' => [
            'strict' => true,
            'failOn' => 'CRITICAL,HIGH',
            'auditLevel' => 'high',
        ],
    ]))->render();

    // Trivy image scan severity escalates
    expect($workflowContent)->toContain("severity: 'CRITICAL,HIGH'");

    // NPM audit level escalates
    expect($workflowContent)->toContain('npm audit --audit-level=high');
});

test('the workflow connects to NetBird VPN before touching kubectl when the cluster has one', function (): void {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData(['vpnHost' => 'vpn.example.com']))->render();

    expect($workflowContent)
        ->toContain('Connect to NetBird VPN')
        ->toContain('sudo netbird up --management-url https://vpn.example.com --setup-key ${{ secrets.PRODUCTION_NETBIRD_SETUP_KEY }}');

    $vpnPos = strpos($workflowContent, 'Connect to NetBird VPN');
    $contextPos = strpos($workflowContent, 'Set Kubernetes context');
    expect($vpnPos)->not->toBeFalse()->and($contextPos)->not->toBeFalse()
        ->and($vpnPos)->toBeLessThan($contextPos);
});

test('the workflow has no VPN step at all when the environment has no VPN', function (): void {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData(['vpnHost' => null]))->render();

    expect($workflowContent)->not->toContain('Connect to NetBird VPN')
        ->not->toContain('netbird up');
});

test('the workflow bakes public env vars as literal echo lines, never a secret', function (): void {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData())->render();

    expect($workflowContent)
        ->toContain("echo 'APP_URL=")
        ->toContain("echo 'ASSET_URL=")
        ->not->toContain('ENV_FILE_BASE64')
        ->not->toContain('E_DATA');
});

test('the deploy job refuses to proceed when laravel-secrets is missing, and never creates it', function (): void {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData())->render();

    expect($workflowContent)
        ->toContain('kubectl get secret laravel-secrets -n test-app-production')
        ->toContain('dotenv:push production')
        ->not->toContain('kubectl create secret generic laravel-secrets');

    // The ConfigMap still gets created from the public .env — only the Secret
    // creation moved to `dotenv:push`.
    expect($workflowContent)->toContain('kubectl create configmap laravel-config');
});
