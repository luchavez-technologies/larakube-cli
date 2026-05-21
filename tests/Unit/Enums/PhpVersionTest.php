<?php

use App\Data\ConfigData;
use App\Enums\PhpVersion;

test('php version has correct labels', function () {
    expect(PhpVersion::PHP_8_5->getLabel())->toBe('PHP 8.5 (Latest)')
        ->and(PhpVersion::PHP_7_4->getLabel())->toBe('PHP 7.4');
});

test('php version visibility respects scaffolding', function () {
    $config = ConfigData::from(['isScaffolding' => true]);
    expect(PhpVersion::PHP_8_2->isHidden($config))->toBeTrue()
        ->and(PhpVersion::PHP_8_3->isHidden($config))->toBeFalse();
});

test('php version visibility respects frankenphp', function () {
    $config = ConfigData::from(['serverVariation' => 'frankenphp']);
    expect(PhpVersion::PHP_8_2->isHidden($config))->toBeTrue()
        ->and(PhpVersion::PHP_8_3->isHidden($config))->toBeFalse();
});

test('php version select options are valid', function () {
    $options = PhpVersion::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('8.5', 'PHP 8.5 (Latest)');
});
