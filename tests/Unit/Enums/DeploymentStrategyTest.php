<?php

use App\Enums\DeploymentStrategy;

test('deployment strategy has correct labels', function (): void {
    expect(DeploymentStrategy::SINGLE_NODE->getLabel())->toBe('Single-Node Hero (Optimized for cost/simplicity, using HostPort)')
        ->and(DeploymentStrategy::MULTI_NODE_HA->getLabel())->toBe('Multi-Node HA (Optimized for scale, using managed LoadBalancer)');
});

test('deployment strategy select options are valid', function (): void {
    $options = DeploymentStrategy::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('single-node');
});
