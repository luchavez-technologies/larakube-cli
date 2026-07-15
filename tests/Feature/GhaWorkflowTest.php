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

    $audit = array_merge([
        'skip' => false,
        'strict' => false,
        'withTests' => false,
        'failOn' => 'CRITICAL',
        'auditLevel' => 'critical',
    ], $auditOverrides);

    $secrets = array_merge([
        'k_env' => '${{ secrets.'.$upperEnv.'_KUBECONFIG }}',
        'k_base' => '${{ secrets.KUBECONFIG }}',
        'e_env' => '${{ secrets.'.$upperEnv.'_ENV_FILE_BASE64 }}',
        'e_base' => '${{ secrets.ENV_FILE_BASE64 }}',
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
        'e_data' => '${{ env.E_DATA }}',
        'image_latest' => '${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:latest',
        'image_sha' => '${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:${{ github.sha }}',
        'composer_cache_key' => "composer-\${{ hashFiles('composer.lock') }}",
        'registry_user' => '${{ secrets.'.$upperEnv.'_REGISTRY_USERNAME }}',
        'registry_password' => '${{ secrets.'.$upperEnv.'_REGISTRY_PASSWORD }}',
        'trivy_cache_key' => '${{ runner.os }}-trivy-db',
        'trivy_restore_key' => '${{ runner.os }}-trivy-',
    ], $overrides['gha'] ?? []);
    unset($overrides['gha']);

    return array_merge([
        'config' => $config,
        'environment' => $environment,
        'branch' => 'main',
        'appName' => 'test-app',
        'namespace' => 'test-app-production',
        'podName' => 'web',
        'upperEnv' => $upperEnv,
        'vpnHost' => $vpnHost,
        'secrets' => $secrets,
        'gha' => $gha,
        'audit' => $audit,
    ], $overrides);
}

test('GHA workflow generation uses correct literal injection syntax', function () {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData())->render();

    // Verify Literal Injections
    expect($workflowContent)->toContain('FINAL_KUBE="${{ secrets.PRODUCTION_KUBECONFIG }}"');
    expect($workflowContent)->toContain('FINAL_ENV="${{ secrets.PRODUCTION_ENV_FILE_BASE64 }}"');
    expect($workflowContent)->toContain('REGISTRY_HOST: ghcr.io');
    expect($workflowContent)->toContain('IMAGE_NAME: ${{ github.repository }}');
    expect($workflowContent)->toContain('REGISTRY_PROVIDER: ghcr');
    expect($workflowContent)->toContain('kubeconfig: ${{ env.K_DATA }}');

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

test('GHA workflow with default audit config emits security gates and split build', function () {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData())->render();

    // Phase 1 — Audit gates are present
    expect($workflowContent)
        ->toContain('gitleaks/gitleaks-action@v2')
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

test('GHA workflow with --skip-audit produces the lean pipeline without gates', function () {
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

test('GHA workflow with --strict uses CRITICAL,HIGH severity', function () {
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

test('the workflow connects to NetBird VPN before touching kubectl when the cluster has one', function () {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData(['vpnHost' => 'vpn.example.com']))->render();

    expect($workflowContent)
        ->toContain('Connect to NetBird VPN')
        ->toContain('sudo netbird up --management-url https://vpn.example.com --setup-key ${{ secrets.PRODUCTION_NETBIRD_SETUP_KEY }}');

    $vpnPos = strpos($workflowContent, 'Connect to NetBird VPN');
    $contextPos = strpos($workflowContent, 'Set Kubernetes context');
    expect($vpnPos)->not->toBeFalse()->and($contextPos)->not->toBeFalse()
        ->and($vpnPos)->toBeLessThan($contextPos);
});

test('the workflow has no VPN step at all when the environment has no VPN', function () {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaViewData(['vpnHost' => null]))->render();

    expect($workflowContent)->not->toContain('Connect to NetBird VPN')
        ->not->toContain('netbird up');
});
