<?php

use App\Enums\OperatingSystem;

test('operating system has correct labels', function (): void {
    expect(OperatingSystem::DEBIAN->getLabel())->toBe('Debian (Stable, widely compatible, larger image)')
        ->and(OperatingSystem::ALPINE->getLabel())->toBe('Alpine (Lightweight, smaller image, minimal footprint)');
});

test('operating system suffixes', function (): void {
    expect(OperatingSystem::ALPINE->getSuffix())->toBe('-alpine')
        ->and(OperatingSystem::DEBIAN->getSuffix())->toBeNull();
});

test('operating system select options are valid', function (): void {
    $options = OperatingSystem::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('alpine');
});
