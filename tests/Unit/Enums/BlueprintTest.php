<?php

use App\Enums\Blueprint;

test('blueprint has correct labels', function () {
    expect(Blueprint::LARAVEL->getLabel())->toBe('Laravel (Standard)')
        ->and(Blueprint::FILAMENT->getLabel())->toBe('Filament PHP (Admin Panel)')
        ->and(Blueprint::STATAMIC->getLabel())->toBe('Statamic (CMS)');
});

test('blueprint has correct descriptions', function () {
    expect(Blueprint::LARAVEL->description())->toBe('A clean, modern Laravel application.')
        ->and(Blueprint::FILAMENT->description())->toBe('The elegant TALL stack admin panel for Laravel.')
        ->and(Blueprint::STATAMIC->description())->toBe('The radical, flat-file (or database) CMS for Laravel.');
});

test('blueprint select options are valid', function () {
    $options = Blueprint::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveCount(2) // Statamic is hidden
        ->and($options)->toHaveKey('laravel', 'Laravel (Standard)');
});

test('statamic is hidden', function () {
    expect(Blueprint::STATAMIC->isHidden())->toBeTrue()
        ->and(Blueprint::LARAVEL->isHidden())->toBeFalse();
});

test('blueprint php extensions', function () {
    expect(Blueprint::STATAMIC->getPhpExtensions())->toContain('gd', 'exif')
        ->and(Blueprint::FILAMENT->getPhpExtensions())->toContain('intl')
        ->and(Blueprint::LARAVEL->getPhpExtensions())->toBeEmpty();
});
