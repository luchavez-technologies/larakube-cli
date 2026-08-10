<?php

use App\Commands\Cloud\CloudConfigureTunnelCommand;
use App\Data\EnvironmentData;
use App\Data\TunnelData;
use App\Enums\TunnelProvider;

test('cloudflare has a health probe, localtonet does not', function () {
    expect(TunnelProvider::CLOUDFLARE->hasHealthProbe())->toBeTrue()
        ->and(TunnelProvider::LOCALTONET->hasHealthProbe())->toBeFalse();
});

test('every provider has a non-empty image', function () {
    foreach (TunnelProvider::cases() as $p) {
        expect($p->getImage())->not->toBeEmpty();
    }
});

test('cloudflare args include run and --token', function () {
    $args = TunnelProvider::CLOUDFLARE->getArgs();

    expect($args)->toContain('run')
        ->toContain('--token');
});

test('localtonet command includes --authtoken', function () {
    $args = TunnelProvider::LOCALTONET->getArgs();

    expect(implode(' ', $args))->toContain('--authtoken');
});

test('TunnelData round-trips through spatie data', function () {
    $data = TunnelData::from(['provider' => 'cloudflare']);

    expect($data->provider)->toBe(TunnelProvider::CLOUDFLARE);
});

test('cloud:configure:tunnel command has remove option', function () {
    $cmd = new CloudConfigureTunnelCommand;

    expect($cmd->getDefinition()->hasOption('remove'))->toBeTrue()
        ->and($cmd->getDefinition()->hasOption('provider'))->toBeTrue()
        ->and($cmd->getDefinition()->hasOption('token'))->toBeTrue();
});

test('EnvironmentData tunnel field defaults to null', function () {
    $env = new EnvironmentData;

    expect($env->tunnel)->toBeNull();
});
