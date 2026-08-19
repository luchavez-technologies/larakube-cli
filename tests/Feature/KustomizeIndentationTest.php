<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Enums\IngressController;
use App\Enums\PhpVersion;
use App\Enums\ServerVariation;

test('Kustomize: AWS ALB production patch has valid indentation', function (): void {
    $config = new ConfigData(name: 'indent-test');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setPhpVersion(PhpVersion::PHP_8_5);
    $config->environments['production'] = new EnvironmentData(ingress: IngressController::AWS_ALB);

    $manifests = generateManifestsAsArray($config);

    // If it was malformed, generateManifestsAsArray would have skipped it
    // or failed to parse it as an array.
    expect($manifests)->toHaveKey('overlays/production/ingress-patch.yaml');

    $patch = $manifests['overlays/production/ingress-patch.yaml'];
    expect($patch)->toBeArray()
        ->and($patch['kind'])->toBe('Ingress');

    // Verify specific ALB annotations exist in the parsed array
    $annotations = $patch['metadata']['annotations'];
    expect($annotations)->toHaveKey('alb.ingress.kubernetes.io/scheme')
        ->and($annotations['alb.ingress.kubernetes.io/scheme'])->toBe('internet-facing')
        ->and($annotations)->toHaveKey('alb.ingress.kubernetes.io/listen-ports');
});

test('Kustomize: NGINX production patch has valid indentation', function (): void {
    $config = new ConfigData(name: 'indent-test-nginx');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setPhpVersion(PhpVersion::PHP_8_5);
    $config->environments['production'] = new EnvironmentData(ingress: IngressController::NGINX);

    $manifests = generateManifestsAsArray($config);

    expect($manifests)->toHaveKey('overlays/production/ingress-patch.yaml');

    $patch = $manifests['overlays/production/ingress-patch.yaml'];
    $annotations = $patch['metadata']['annotations'];

    expect($annotations)->toHaveKey('kubernetes.io/ingress.class')
        ->and($annotations['kubernetes.io/ingress.class'])->toBe('nginx');
});
