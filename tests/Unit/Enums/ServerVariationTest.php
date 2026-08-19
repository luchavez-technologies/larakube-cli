<?php

use App\Data\ConfigData;
use App\Enums\LaravelFeature;
use App\Enums\ServerVariation;

test('server variation has correct labels', function (): void {
    expect(ServerVariation::FPM_NGINX->getLabel())->toBe('PHP-FPM + NGINX (Traditional, widely adopted)')
        ->and(ServerVariation::FRANKENPHP->getLabel())->toBe('FrankenPHP (Laravel Octane, worker mode, HTTP/2 & HTTP/3)')
        ->and(ServerVariation::FPM_APACHE->getLabel())->toBe('PHP-FPM + Apache (Ideal for WordPress, .htaccess support)');
});

test('server variation container ports', function (): void {
    expect(ServerVariation::FPM_NGINX->containerPort())->toBe(8080)
        ->and(ServerVariation::FRANKENPHP->containerPort())->toBe(8080);
});

test('server variation traefik schemes', function (): void {
    expect(ServerVariation::FPM_NGINX->traefikScheme())->toBe('http')
        ->and(ServerVariation::FRANKENPHP->traefikScheme())->toBe('http');
});

test('frankenphp has octane dependency', function (): void {
    $config = ConfigData::from([]);
    expect(ServerVariation::FRANKENPHP->getDependencies($config))->toContain(LaravelFeature::OCTANE)
        ->and(ServerVariation::FPM_NGINX->getDependencies($config))->toBeEmpty();
});

test('server variation select options are valid', function (): void {
    $options = ServerVariation::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('fpm-nginx');
});

test('server variation reload commands', function (): void {
    expect(ServerVariation::FRANKENPHP->getReloadCommand())->toBe('php artisan octane:reload')
        ->and(ServerVariation::FPM_NGINX->getReloadCommand())->toBeNull()
        ->and(ServerVariation::FPM_APACHE->getReloadCommand())->toBeNull();
});
