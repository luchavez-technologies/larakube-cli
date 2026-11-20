<?php

use App\Data\ConfigData;
use App\Enums\PhpVersion;

test('php version has correct labels', function (): void {
    expect(PhpVersion::PHP_8_6->getLabel())->toBe('PHP 8.6 (Newest)')
        ->and(PhpVersion::PHP_8_5->getLabel())->toBe('PHP 8.5 (Recommended)')
        ->and(PhpVersion::PHP_7_4->getLabel())->toBe('PHP 7.4');
});

test('php version visibility respects scaffolding', function (): void {
    $config = ConfigData::from(['isScaffolding' => true]);
    expect(PhpVersion::PHP_8_2->isHidden($config))->toBeTrue()
        ->and(PhpVersion::PHP_8_3->isHidden($config))->toBeFalse();
});

test('php version visibility respects frankenphp', function (): void {
    $config = ConfigData::from(['serverVariation' => 'frankenphp']);
    expect(PhpVersion::PHP_8_2->isHidden($config))->toBeTrue()
        ->and(PhpVersion::PHP_8_3->isHidden($config))->toBeFalse();
});

test('php version select options are valid', function (): void {
    $options = PhpVersion::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('8.6')
        ->and($options['8.5'])->toBe('PHP 8.5 (Recommended)');
});

test('a project that names no PHP version still gets 8.5, not the newest', function (): void {
    expect((new ConfigData)->getPhpVersion())->toBe(PhpVersion::PHP_8_5);
});
