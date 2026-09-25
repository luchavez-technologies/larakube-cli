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
test('FLOW productName() reports the real engine when known, n8n by default', function (): void {
    expect(ClusterTool::FLOW->productName())->toBe('n8n')
        ->and(ClusterTool::FLOW->productName('n8n'))->toBe('n8n')
        ->and(ClusterTool::FLOW->productName('windmill'))->toBe('Windmill');
});

test('FLOW commonsDatabaseList() returns both when the engine is unspecified, only one when known', function (): void {
    expect(ClusterTool::FLOW->commonsDatabases())->toEqualCanonicalizing(['n8n', 'windmill'])
        ->and(ClusterTool::FLOW->commonsDatabases(engine: 'n8n'))->toBe(['n8n'])
        ->and(ClusterTool::FLOW->commonsDatabases(engine: 'windmill'))->toBe(['windmill']);
});

test('FLOW deploymentName() targets the real per-engine Deployment name, not always n8n', function (): void {
    // Confirmed against the real Blade manifests: n8n.blade.php deploys
    // "n8n", windmill.blade.php deploys "windmill" — two
    // genuinely different Deployments. deploymentName() ignoring $engine
    // meant any engine-aware caller (e.g. resolveInstanceEngine()'s live
    // Deployment probe) could never actually tell them apart.
    expect(ClusterTool::FLOW->deploymentName())->toBe('n8n')
        ->and(ClusterTool::FLOW->deploymentName(engine: 'n8n'))->toBe('n8n')
        ->and(ClusterTool::FLOW->deploymentName(engine: 'windmill'))->toBe('windmill');
});

test('FLOW smtpEnv() refuses for a known Windmill engine instead of targeting the n8n Deployment', function (): void {
    $default = ClusterTool::FLOW->smtpEnv();
    expect($default)->not->toBeNull()
        ->and($default['deployment'])->toBe('n8n');

    $n8n = ClusterTool::FLOW->smtpEnv('n8n');
    expect($n8n)->toBe($default)
        ->and(ClusterTool::FLOW->smtpEnv('windmill'))->toBeNull();
});

test('each FLOW engine names its resources per instance through ToolInstance', function (): void {
    $n8n = App\Data\ToolInstance::forHost(ClusterTool::FLOW, 'flow.example.com', 'n8n');
    $windmill = App\Data\ToolInstance::forHost(ClusterTool::FLOW, 'jobs.example.com', 'windmill');

    expect($n8n->deployment())->toBe('n8n-flow-example-com')
        ->and($n8n->secret())->toBe('n8n-secrets-flow-example-com')
        ->and($n8n->volume())->toBe('n8n-storage-flow-example-com')
        ->and($n8n->database())->toBe('n8n_flow_example_com')
        ->and($n8n->vpnMiddleware()?->name)->toBe('n8n-vpn-only-flow-example-com')
        ->and(ClusterTool::FLOW->smtpEnv('n8n', $n8n->instance))->toMatchArray([
            'deployment' => 'n8n-flow-example-com',
            'secret' => 'n8n-smtp-flow-example-com',
        ])
        ->and($windmill->deployment())->toBe('windmill-jobs-example-com')
        ->and($windmill->database())->toBe('windmill_jobs_example_com')
        ->and($windmill->vpnMiddleware()?->name)->toBe('windmill-vpn-only-jobs-example-com');
});

test('FLOW templates take every image from the engine class, never a literal tag', function (string $template): void {
    $source = (string) file_get_contents(base_path("resources/views/k8s/flow/{$template}.blade.php"));

    preg_match_all('/^\s*image:\s*(.+)$/m', $source, $m);

    expect($m[1])->not->toBeEmpty()
        ->and(array_filter($m[1], fn (string $image) => ! str_starts_with(trim($image), '{{ $tool->image(')))->toBeEmpty();
})->with(['n8n', 'windmill']);

test('every FLOW engine pins every image it declares to an explicit version', function (): void {
    foreach (App\Enums\FlowTool::cases() as $engine) {
        foreach ($engine->tool()->images() as $key => $image) {
            expect($image)->toMatch('/:[0-9][^:\/]*$/', "{$engine->value}.{$key} is not pinned to a version");
        }
    }
});
