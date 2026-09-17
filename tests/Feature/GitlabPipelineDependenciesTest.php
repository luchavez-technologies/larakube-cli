<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use Symfony\Component\Yaml\Yaml;

function gitlabDependenciesPipeline(?AppFramework $framework): array
{
    $config = new ConfigData(name: 'shop');
    $config->framework = $framework;
    $static = (bool) $framework?->isStaticSpa();

    return Yaml::parse(view('k8s.cloud-pilot-deploy-gitlab', [
        'config' => $config,
        'appName' => 'shop',
        'podName' => 'web',
        'cloudEnvs' => ['production' => [
            'branch' => 'main',
            'upperName' => 'PRODUCTION',
            'namespace' => 'shop-production',
            'registry' => 'gitlab',
            'registry_host' => '$CI_REGISTRY',
            'imageLatest' => '$CI_REGISTRY/$CI_PROJECT_PATH:latest',
            'imageSha' => '$CI_REGISTRY/$CI_PROJECT_PATH:$CI_COMMIT_SHA',
            'static' => $static,
            'publicEnvScript' => "echo 'APP_URL=https://shop.example.com' >> .env",
        ]],
    ])->render());
}

test('a PHP pipeline installs Composer dependencies in its own job and hands vendor to the build', function (): void {
    $pipeline = gitlabDependenciesPipeline(AppFramework::LARAVEL);

    expect($pipeline['stages'])->toBe(['dependencies', 'build', 'deploy'])
        ->and($pipeline['composer:production']['image'])->toStartWith('docker.io/library/composer:')
        ->and($pipeline['composer:production']['artifacts']['paths'])->toBe(['vendor/'])
        ->and($pipeline['build:production']['needs'])->toBe(['composer:production']);
});

test('a static pipeline has no Composer job', function (): void {
    $pipeline = gitlabDependenciesPipeline(AppFramework::ASTRO);

    expect($pipeline)->not->toHaveKey('composer:production')
        ->and($pipeline['build:production'])->not->toHaveKey('needs');
});
