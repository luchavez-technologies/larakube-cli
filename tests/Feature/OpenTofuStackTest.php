<?php

use App\Data\GlobalConfigData;
use App\Data\StackData;

test('StackData round-trips through spatie data and bind is idempotent', function (): void {
    $stack = new StackData(
        name: 'larakube-acme-prod-vps',
        kind: 'vps',
        region: 'sgp1',
        ip: '203.0.113.7',
        createdAt: '2026-06-28T00:00:00+00:00',
    );
    $stack->bind('acme', 'production');
    $stack->bind('acme', 'production'); // duplicate ignored
    $stack->bind('acme', 'staging');

    $back = StackData::from($stack->toArray());

    expect($back->name)->toBe('larakube-acme-prod-vps')
        ->and($back->kind)->toBe('vps')
        ->and($back->ip)->toBe('203.0.113.7')
        ->and($back->bindings)->toBe(['acme/production', 'acme/staging']);
});

test('GlobalConfigData stack registry put/find/remove round-trips', function (): void {
    $config = new GlobalConfigData;
    $config->putStack(new StackData(name: 'stack-a', kind: 'vps', region: 'nyc1'));
    $config->putStack(new StackData(name: 'stack-b', kind: 'doks', region: 'ams3', context: 'do-ams3-stack-b'));

    // Survives a serialize/hydrate cycle (as load() would do).
    $reloaded = GlobalConfigData::from($config->toArray());

    expect($reloaded->getStacks())->toHaveCount(2)
        ->and($reloaded->findStack('stack-b')?->context)->toBe('do-ams3-stack-b')
        ->and($reloaded->findStack('missing'))->toBeNull();

    $reloaded->removeStack('stack-a');
    expect($reloaded->findStack('stack-a'))->toBeNull()
        ->and($reloaded->getStacks())->toHaveCount(1);
});

test('ensureTofuPassphrase mints once and is stable; removeStack drops it', function (): void {
    $config = new GlobalConfigData;

    $first = $config->ensureTofuPassphrase('stack-x');
    $second = $config->ensureTofuPassphrase('stack-x');

    expect($first)->toBe($second)
        ->and(strlen($first))->toBeGreaterThanOrEqual(16);

    $config->putStack(new StackData(name: 'stack-x', kind: 'vps'));
    $config->removeStack('stack-x');

    expect($config->getTofuPassphrase('stack-x'))->toBeNull();
});

test('DO token setter trims and clears', function (): void {
    $config = new GlobalConfigData;
    $config->setDoToken('  dop_v1_abc  ');
    expect($config->getDoToken())->toBe('dop_v1_abc');

    $config->setDoToken(null);
    expect($config->getDoToken())->toBeNull();
});

test('defaultCloudProvider defaults to do and can be changed', function (): void {
    $config = new GlobalConfigData;
    expect($config->getDefaultCloudProvider())->toBe('do');

    $config->setDefaultCloudProvider('aws');
    expect($config->getDefaultCloudProvider())->toBe('aws');

    $reloaded = GlobalConfigData::from($config->toArray());
    expect($reloaded->getDefaultCloudProvider())->toBe('aws');
});
