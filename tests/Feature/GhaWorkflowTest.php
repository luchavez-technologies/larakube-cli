<?php

use App\Data\ConfigData;
use App\Enums\PackageManager;

test('GHA workflow generation uses correct literal injection syntax', function () {
    $config = new ConfigData(name: 'test-app');
    $config->setPackageManager(PackageManager::NPM);
    $environment = 'production';
    $appName = 'test-app';
    $namespace = 'test-app-production';
    $podName = 'web';
    $upperEnv = 'PRODUCTION';
    $branch = 'main';

    $workflowContent = view('k8s.cloud-pilot-deploy', [
        'config' => $config,
        'environment' => $environment,
        'branch' => $branch,
        'appName' => $appName,
        'namespace' => $namespace,
        'podName' => $podName,
        'upperEnv' => $upperEnv,
        'secrets' => [
            'k_env' => '${{ secrets.'.$upperEnv.'_KUBECONFIG }}',
            'k_base' => '${{ secrets.KUBECONFIG }}',
            'e_env' => '${{ secrets.'.$upperEnv.'_ENV_FILE_BASE64 }}',
            'e_base' => '${{ secrets.ENV_FILE_BASE64 }}',
        ],
        'gha' => [
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
            'dockerhub_user' => '${{ secrets.DOCKERHUB_USERNAME }}',
            'dockerhub_token' => '${{ secrets.DOCKERHUB_TOKEN }}',
        ],
    ])->render();

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
        ->toContain('kind:[ \t]+Namespace');

    // The image tags must render as real GitHub expressions — NOT mangled into
    // compiled Blade (the bug: literal {{ }} inside {!! '…' !!} gets post-processed).
    expect($workflowContent)
        ->toContain('tags: ${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:latest,${{ env.REGISTRY_HOST }}/${{ env.IMAGE_NAME }}:${{ github.sha }}')
        ->not->toContain('<?php')      // no compiled-Blade leakage
        ->not->toContain('echo e(');

    // Verify no Blade '@' symbols leaked into the GitHub syntax
    expect($workflowContent)->not->toContain('@{{');

    // Verify no unresolved variable placeholders
    expect($workflowContent)->not->toContain('{{ $upperEnv }}');
});

function ghaWorkflowArgs(array $overrides = []): array
{
    $config = new ConfigData(name: 'test-app');
    $config->setPackageManager(PackageManager::NPM);

    return array_merge([
        'config' => $config,
        'environment' => 'production',
        'branch' => 'main',
        'appName' => 'test-app',
        'namespace' => 'test-app-production',
        'podName' => 'web',
        'upperEnv' => 'PRODUCTION',
        'secrets' => [
            'k_env' => '${{ secrets.PRODUCTION_KUBECONFIG }}',
            'k_base' => '${{ secrets.KUBECONFIG }}',
            'e_env' => '${{ secrets.PRODUCTION_ENV_FILE_BASE64 }}',
            'e_base' => '${{ secrets.ENV_FILE_BASE64 }}',
            'vpn_key' => '${{ secrets.PRODUCTION_NETBIRD_SETUP_KEY }}',
        ],
        'gha' => [
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
        ],
    ], $overrides);
}

test('the workflow connects to NetBird VPN before touching kubectl when the cluster has one', function () {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaWorkflowArgs(['vpnHost' => 'vpn.example.com']))->render();

    expect($workflowContent)
        ->toContain('Connect to NetBird VPN')
        ->toContain('sudo netbird up --management-url https://vpn.example.com --setup-key ${{ secrets.PRODUCTION_NETBIRD_SETUP_KEY }}');

    $vpnPos = strpos($workflowContent, 'Connect to NetBird VPN');
    $contextPos = strpos($workflowContent, 'Set Kubernetes context');
    expect($vpnPos)->not->toBeFalse()->and($contextPos)->not->toBeFalse()
        ->and($vpnPos)->toBeLessThan($contextPos);
});

test('the workflow has no VPN step at all when the environment has no VPN', function () {
    $workflowContent = view('k8s.cloud-pilot-deploy', ghaWorkflowArgs(['vpnHost' => null]))->render();

    expect($workflowContent)->not->toContain('Connect to NetBird VPN')
        ->not->toContain('netbird up');
});
