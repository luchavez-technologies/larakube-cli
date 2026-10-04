<?php

use App\Services\Scaffolding\BuilderImage;

test('a PHP version with a published builder image names it in the registry, and others have none', function (): void {
    expect(BuilderImage::php('8.4'))->toBe('ghcr.io/luchavez-technologies/larakube-builder/php:8.4')
        ->and(BuilderImage::php('8.3'))->not->toBeNull()
        ->and(BuilderImage::php('8.2'))->toBeNull()
        ->and(BuilderImage::php('7.4'))->toBeNull();
});

test('a prebuilt builder only runs the installer, a plain image first installs Node and the installer', function (): void {
    expect(BuilderImage::laravelNewScript(true, 'apk add --no-cache nodejs npm', 'my-app', '--no-boost'))
        ->toBe('laravel new my-app --no-boost')
        ->and(BuilderImage::laravelNewScript(false, 'apk add --no-cache nodejs npm', 'my-app', '--no-boost'))->toBe('apk add --no-cache nodejs npm && composer config -g bin-dir /usr/local/bin && composer global require laravel/installer && laravel new my-app --no-boost');
});

test('new prefers the builder image and keeps the plain image as the fallback', function (): void {
    $source = (string) file_get_contents(base_path('app/Commands/NewCommand.php'));

    expect($source)->toContain('BuilderImage::php(')
        ->toContain('BuilderImage::laravelNewScript($prebuilt')
        ->toContain('Could not pull the prebuilt builder image');
});

test('the Python builder names Django\'s image, and Statamic and Django use their builders with a fallback', function (): void {
    expect(BuilderImage::python('3.12'))->toBe('ghcr.io/luchavez-technologies/larakube-builder/python:3.12')
        ->and(BuilderImage::python('3.9'))->toBeNull();

    $statamic = (string) file_get_contents(base_path('app/Commands/Statamic/StatamicNewCommand.php'));
    $django = (string) file_get_contents(base_path('app/Commands/Django/DjangoNewCommand.php'));

    expect($statamic)->toContain('BuilderImage::php(')->toContain('Could not pull the prebuilt builder image')
        ->and($django)->toContain('BuilderImage::python(')->toContain('pip install --no-cache-dir django && ');
});
