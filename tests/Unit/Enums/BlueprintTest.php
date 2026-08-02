<?php

use App\Enums\Blueprint;

test('blueprint has correct labels', function () {
    expect(Blueprint::LARAVEL->getLabel())->toBe('Laravel (Standard)')
        ->and(Blueprint::FILAMENT->getLabel())->toBe('Filament PHP (Admin Panel)');
});

test('blueprint has correct descriptions', function () {
    expect(Blueprint::LARAVEL->description())->toBe('A clean, modern Laravel application.')
        ->and(Blueprint::FILAMENT->description())->toBe('The elegant TALL stack admin panel for Laravel.');
});

test('blueprint select options are valid', function () {
    $options = Blueprint::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveCount(2)
        ->and($options)->toHaveKey('laravel', 'Laravel (Standard)');
});

test('blueprint php extensions', function () {
    expect(Blueprint::FILAMENT->getPhpExtensions())->toContain('intl')
        ->and(Blueprint::LARAVEL->getPhpExtensions())->toBeEmpty();
});
