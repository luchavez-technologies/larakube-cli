<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\FrontendStack;
use App\Enums\PackageManager;
use App\Enums\ServerVariation;

dataset('packageManagers', [
    'NPM' => PackageManager::NPM,
    'PNPM' => PackageManager::PNPM,
    'BUN' => PackageManager::BUN,
    'YARN' => PackageManager::YARN,
]);

function nodeHardeningArtifacts(PackageManager $pm): array
{
    $config = new ConfigData(name: 'harden-'.$pm->value);
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setFrontend(FrontendStack::REACT);
    $config->setPackageManager($pm);

    $manifests = generateManifestsAsArray($config);

    expect($manifests)->toHaveKeys(['overlays/local/node-deployment.yaml', 'base/laravel.yaml']);

    $nodeDeployment = collect($manifests['overlays/local/node-deployment.yaml'])
        ->firstWhere('kind', 'Deployment');
    $webDeployment = collect($manifests['base/laravel.yaml'])
        ->first(fn ($doc) => is_array($doc)
            && ($doc['kind'] ?? null) === 'Deployment'
            && ($doc['metadata']['name'] ?? null) === 'web');

    expect($nodeDeployment)->not->toBeNull()
        ->and($webDeployment)->not->toBeNull();

    $nodeContainer = collect($nodeDeployment['spec']['template']['spec']['containers'])
        ->firstWhere('name', 'node');
    $webContainer = collect($webDeployment['spec']['template']['spec']['containers'])
        ->firstWhere('name', 'php');

    expect($nodeContainer)->not->toBeNull()
        ->and($webContainer)->not->toBeNull();

    return ['node' => $nodeContainer, 'web' => $webContainer];
}

test('Node deployment: does not inject VITE_DEV_SERVER_URL (plugin reads only server.origin from vite.config.js)', function (PackageManager $pm): void {
    $artifacts = nodeHardeningArtifacts($pm);
    $envNames = collect($artifacts['node']['env'] ?? [])->pluck('name')->all();

    expect($envNames)->not->toContain('VITE_DEV_SERVER_URL');
})->with('packageManagers');

test('Node deployment: declares no livenessProbe (would SIGTERM the dev server and delete public/hot)', function (PackageManager $pm): void {
    expect(nodeHardeningArtifacts($pm)['node'])->not->toHaveKey('livenessProbe');
})->with('packageManagers');

test('Node deployment: keeps readinessProbe on port 5173 to gate ingress until Vite is listening', function (PackageManager $pm): void {
    $node = nodeHardeningArtifacts($pm)['node'];

    expect($node)->toHaveKey('readinessProbe')
        ->and($node['readinessProbe']['tcpSocket']['port'])->toBe(5173);
})->with('packageManagers');

test('Node deployment: shares image with web/php for UID/GID parity (unified dev image promise)', function (PackageManager $pm): void {
    $artifacts = nodeHardeningArtifacts($pm);

    expect($artifacts['node']['image'])->toBe($artifacts['web']['image']);
})->with('packageManagers');
