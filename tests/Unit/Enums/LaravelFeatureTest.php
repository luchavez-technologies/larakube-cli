<?php

use App\Data\ConfigData;
use App\Enums\LaravelFeature;

test('laravel feature has correct labels', function (): void {
    expect(LaravelFeature::HORIZON->getLabel())->toBe('Horizon (with Redis)')
        ->and(LaravelFeature::REVERB->getLabel())->toBe('Reverb')
        ->and(LaravelFeature::OCTANE->getLabel())->toBe('Octane (requires FrankenPHP)');
});

test('laravel feature pod names', function (): void {
    expect(LaravelFeature::TASK_SCHEDULING->getPodName())->toBe('scheduler')
        ->and(LaravelFeature::QUEUES->getPodName())->toBe('queues')
        ->and(LaravelFeature::HORIZON->getPodName())->toBe('horizon');
});

test('octane is hidden when using frankenphp', function (): void {
    $config = ConfigData::from(['serverVariation' => 'frankenphp']);
    expect(LaravelFeature::OCTANE->isHidden($config))->toBeTrue();

    $config = ConfigData::from(['serverVariation' => 'fpm-nginx']);
    expect(LaravelFeature::OCTANE->isHidden($config))->toBeFalse();
});

test('laravel feature select options are valid', function (): void {
    $options = LaravelFeature::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('horizon', 'Horizon (with Redis)');
});

test('from pod name mapping', function (): void {
    expect(LaravelFeature::fromPodName('scheduler'))->toBe(LaravelFeature::TASK_SCHEDULING)
        ->and(LaravelFeature::fromPodName('horizon'))->toBe(LaravelFeature::HORIZON)
        ->and(LaravelFeature::fromPodName('reverb'))->toBe(LaravelFeature::REVERB);
});

test('laravel feature reload commands', function (): void {
    expect(LaravelFeature::HORIZON->getReloadCommand())->toBe('php artisan horizon:terminate')
        ->and(LaravelFeature::QUEUES->getReloadCommand())->toBe('php artisan queue:restart')
        ->and(LaravelFeature::REVERB->getReloadCommand())->toBe('php artisan reverb:restart')
        ->and(LaravelFeature::TASK_SCHEDULING->getReloadCommand())->toBeNull()
        ->and(LaravelFeature::SCOUT->getReloadCommand())->toBeNull()
        ->and(LaravelFeature::OCTANE->getReloadCommand())->toBeNull()
        ->and(LaravelFeature::SSR->getReloadCommand())->toBeNull();
});

test('ssr feature exposes node-ssr pod and Inertia label', function (): void {
    expect(LaravelFeature::SSR->getPodName())->toBe('node-ssr')
        ->and(LaravelFeature::fromPodName('node-ssr'))->toBe(LaravelFeature::SSR)
        ->and(LaravelFeature::SSR->getLabel())->toBe('Inertia SSR (Server-Side Rendering)');
});

test('ssr feature injects Inertia env vars in production only', function (): void {
    $config = ConfigData::from(['name' => 'ssr-test']);

    $prodEnv = LaravelFeature::SSR->getPublicEnvironmentVariables($config, 'production');
    expect($prodEnv)->toHaveKey('INERTIA_SSR_ENABLED', 'true')
        ->and($prodEnv['INERTIA_SSR_URL'])->toEndWith(':13714')
        ->and($prodEnv['INERTIA_SSR_URL'])->toStartWith('http://');

    // Local must NOT inject SSR env vars — local dev should not call SSR by default.
    $localEnv = LaravelFeature::SSR->getPublicEnvironmentVariables($config, 'local');
    expect($localEnv)->toBeEmpty();
});

test('locally, reloadable processes run in a loop that restarts them after a graceful exit', function (): void {
    $args = json_decode((string) LaravelFeature::HORIZON->getLocalK8sDeploymentArgs(), true);

    expect($args[0])->toBe('sh')
        ->and($args[1])->toBe('-c')
        ->and($args[2])->toContain('while true; do php artisan horizon & child=$!')
        ->and($args[2])->toContain("trap 'kill -TERM")
        ->and(LaravelFeature::QUEUES->getLocalK8sDeploymentArgs())->toContain('php artisan queue:work')
        ->and(LaravelFeature::REVERB->getLocalK8sDeploymentArgs())->toContain('php artisan reverb:start --host=0.0.0.0 --port=8081')
        ->and(LaravelFeature::TASK_SCHEDULING->getLocalK8sDeploymentArgs())->toBeNull()
        ->and(LaravelFeature::SCOUT->getLocalK8sDeploymentArgs())->toBeNull();
});
