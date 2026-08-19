<?php

use App\Enums\ClusterTool;

/**
 * Pins ClusterTool::forDeployment() — the reverse lookup dynamic PVC backup
 * discovery relies on to decide whether a live Deployment is backup-worthy.
 */
test('resolves an exact PRIMARY component match', function (): void {
    $match = ClusterTool::forDeployment('forgejo');
    expect($match['tool'])->toBe(ClusterTool::GIT)
        ->and($match['component']->key)->toBe('server');
});

test('a WORKER whose name prefixes another tool\'s deployment is never mistaken for an instance-suffixed copy of it', function (): void {
    // "forgejo-runner" starts with "forgejo-" — the exact-match-first pass
    // must resolve it to GIT's own "runner" component, never GIT's "server"
    // component read as if "runner" were an instance suffix on it.
    $match = ClusterTool::forDeployment('forgejo-runner');
    expect($match['tool'])->toBe(ClusterTool::GIT)
        ->and($match['component']->key)->toBe('runner');
});

test('resolves an instance-suffixed Deployment to its base component', function (): void {
    $match = ClusterTool::forDeployment('data-pocketbase-blog-example-com');
    expect($match['tool'])->toBe(ClusterTool::DATA)
        ->and($match['component']->key)->toBe('app');
});

test('checks every engine variant, not just the default', function (): void {
    // FLOW's default engine is n8n (flow-n8n); flow-windmill must still
    // resolve correctly since it's a real, live-possible Deployment name.
    $match = ClusterTool::forDeployment('flow-windmill');
    expect($match['tool'])->toBe(ClusterTool::FLOW);
});

test('returns null for an unmanaged Deployment — the exclusion mechanism itself', function (): void {
    expect(ClusterTool::forDeployment('prometheus-server'))->toBeNull();
});

test('a compound tool\'s bundled-storage component resolves by its own exact name', function (): void {
    $match = ClusterTool::forDeployment('chat-synapse-db');
    expect($match['tool'])->toBe(ClusterTool::CHAT)
        ->and($match['component']->key)->toBe('db');
});
