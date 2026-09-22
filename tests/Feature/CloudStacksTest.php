<?php

use App\Data\GlobalConfigData;
use App\Data\StackData;

test('cloud:stacks runs cleanly and outputs registered stacks', function (): void {
    $this->artisan('cloud:stacks')
        ->assertExitCode(0);
});

test('StackData stores and preserves provider correctly', function (): void {
    $config = new GlobalConfigData;
    $doStack = new StackData(
        name: 'do-vps',
        provider: 'do',
        kind: 'vps',
        region: 'sgp1',
        ip: '1.2.3.4',
    );
    $gcpStack = new StackData(
        name: 'gcp-vps',
        provider: 'gcp',
        kind: 'vps',
        region: 'us-central1',
        ip: '34.1.2.3',
    );

    $config->putStack($doStack);
    $config->putStack($gcpStack);

    $stacks = $config->getStacks();
    expect($stacks)->toHaveCount(2)
        ->and($stacks['do-vps']->provider)->toBe('do')
        ->and($stacks['gcp-vps']->provider)->toBe('gcp')
        ->and($stacks['gcp-vps']->region)->toBe('us-central1')
        ->and($stacks['gcp-vps']->ip)->toBe('34.1.2.3');
});
