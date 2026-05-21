<?php

use App\Data\ConfigData;
use App\Enums\LaravelFeature;

test('laravel feature has correct labels', function () {
    expect(LaravelFeature::HORIZON->getLabel())->toBe('Horizon (with Redis)')
        ->and(LaravelFeature::REVERB->getLabel())->toBe('Reverb')
        ->and(LaravelFeature::OCTANE->getLabel())->toBe('Octane (requires FrankenPHP)');
});

test('laravel feature pod names', function () {
    expect(LaravelFeature::TASK_SCHEDULING->getPodName())->toBe('scheduler')
        ->and(LaravelFeature::QUEUES->getPodName())->toBe('queues')
        ->and(LaravelFeature::HORIZON->getPodName())->toBe('horizon');
});

test('octane is hidden when using frankenphp', function () {
    $config = ConfigData::from(['serverVariation' => 'frankenphp']);
    expect(LaravelFeature::OCTANE->isHidden($config))->toBeTrue();

    $config = ConfigData::from(['serverVariation' => 'fpm-nginx']);
    expect(LaravelFeature::OCTANE->isHidden($config))->toBeFalse();
});

test('laravel feature select options are valid', function () {
    $options = LaravelFeature::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('horizon', 'Horizon (with Redis)');
});

test('from pod name mapping', function () {
    expect(LaravelFeature::fromPodName('scheduler'))->toBe(LaravelFeature::TASK_SCHEDULING)
        ->and(LaravelFeature::fromPodName('horizon'))->toBe(LaravelFeature::HORIZON)
        ->and(LaravelFeature::fromPodName('reverb'))->toBe(LaravelFeature::REVERB);
});
