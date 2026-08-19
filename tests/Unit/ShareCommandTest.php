<?php

use App\Commands\ShareCommand;
use App\Data\GlobalConfigData;

test('share command has stop, token, and reset options', function (): void {
    $cmd = new ShareCommand;

    expect($cmd->getDefinition()->hasOption('stop'))->toBeTrue()
        ->and($cmd->getDefinition()->hasOption('token'))->toBeTrue()
        ->and($cmd->getDefinition()->hasOption('reset'))->toBeTrue();
});

test('GlobalConfigData share token round-trips', function (): void {
    $config = new GlobalConfigData;
    $config->setShareToken('my-secret-token');

    $restored = GlobalConfigData::from($config->toArray());

    expect($restored->getShareToken())->toBe('my-secret-token');
});

test('GlobalConfigData share URLs are stored per app and merged', function (): void {
    $config = new GlobalConfigData;
    $config->setShareUrls('myapp', ['web' => 'https://myapp.example.com']);
    $config->setShareUrls('myapp', ['hmr' => 'https://hmr.myapp.example.com']);

    $urls = $config->getShareUrls('myapp');

    expect($urls)->toHaveKey('web', 'https://myapp.example.com')
        ->toHaveKey('hmr', 'https://hmr.myapp.example.com');
});

test('GlobalConfigData share URLs are isolated per app', function (): void {
    $config = new GlobalConfigData;
    $config->setShareUrls('app-a', ['web' => 'https://a.example.com']);
    $config->setShareUrls('app-b', ['web' => 'https://b.example.com']);

    expect($config->getShareUrls('app-a')['web'])->toBe('https://a.example.com')
        ->and($config->getShareUrls('app-b')['web'])->toBe('https://b.example.com');
});

test('GlobalConfigData share URLs round-trip through serialization', function (): void {
    $config = new GlobalConfigData;
    $config->setShareUrls('myapp', ['web' => 'https://myapp.example.com', 'hmr' => 'https://hmr.myapp.example.com']);

    $restored = GlobalConfigData::from($config->toArray());

    expect($restored->getShareUrls('myapp'))->toHaveKey('web', 'https://myapp.example.com')
        ->toHaveKey('hmr', 'https://hmr.myapp.example.com');
});

test('GlobalConfigData returns empty array for unknown app', function (): void {
    $config = new GlobalConfigData;

    expect($config->getShareUrls('unknown-app'))->toBeEmpty();
});

test('vite hmr blade template contains VITE_HMR_HOST env override', function (): void {
    $content = file_get_contents(resource_path('views/js/vite-hmr.blade.php'));

    expect($content)->toContain('process.env.VITE_HMR_HOST')
        ->toContain('process.env.VITE_HMR_CLIENT_PORT')
        ->toContain('process.env.VITE_HMR_PROTOCOL');
});
