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
        ->and(LaravelFeature::REVERB->getReloadCommand())->toBeNull()
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
