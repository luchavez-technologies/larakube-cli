<?php

use App\Enums\ClusterTool;

/**
 * Regression tests for the wire-command engine-resolution bug this overhaul
 * exists to fix: FLOW's productName()/commonsDatabaseList()/smtpEnv() used
 * to ignore the $engine parameter entirely (always n8n / always both
 * databases), so a caller that HAD correctly resolved "this instance runs
 * Windmill" still got n8n's answers back. Unspecified-$engine behavior
 * (used by teardown, which deliberately wants "both, to guarantee a clean
 * slate") is pinned as unchanged.
 */
test('FLOW productName() reports the real engine when known, n8n by default', function () {
    expect(ClusterTool::FLOW->productName())->toBe('n8n')
        ->and(ClusterTool::FLOW->productName('n8n'))->toBe('n8n')
        ->and(ClusterTool::FLOW->productName('windmill'))->toBe('Windmill');
});

test('FLOW commonsDatabaseList() returns both when the engine is unspecified, only one when known', function () {
    expect(ClusterTool::FLOW->commonsDatabases())->toEqualCanonicalizing(['n8n', 'windmill'])
        ->and(ClusterTool::FLOW->commonsDatabases(engine: 'n8n'))->toBe(['n8n'])
        ->and(ClusterTool::FLOW->commonsDatabases(engine: 'windmill'))->toBe(['windmill']);
});

test('FLOW deploymentName() targets the real per-engine Deployment name, not always flow-n8n', function () {
    // Confirmed against the real Blade manifests: n8n.blade.php deploys
    // "flow-n8n", windmill.blade.php deploys "flow-windmill" — two
    // genuinely different Deployments. deploymentName() ignoring $engine
    // meant any engine-aware caller (e.g. resolveInstanceEngine()'s live
    // Deployment probe) could never actually tell them apart.
    expect(ClusterTool::FLOW->deploymentName())->toBe('flow-n8n')
        ->and(ClusterTool::FLOW->deploymentName(engine: 'n8n'))->toBe('flow-n8n')
        ->and(ClusterTool::FLOW->deploymentName(engine: 'windmill'))->toBe('flow-windmill');
});

test('FLOW smtpEnv() refuses for a known Windmill engine instead of targeting the n8n Deployment', function () {
    $default = ClusterTool::FLOW->smtpEnv();
    expect($default)->not->toBeNull()
        ->and($default['deployment'])->toBe('flow-n8n');

    $n8n = ClusterTool::FLOW->smtpEnv('n8n');
    expect($n8n)->toBe($default);

    expect(ClusterTool::FLOW->smtpEnv('windmill'))->toBeNull();
});
