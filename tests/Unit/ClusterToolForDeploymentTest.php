<?php

use App\Enums\ClusterTool;

/**
 * Pins ClusterTool::forDeployment() — the reverse lookup dynamic PVC backup
 * discovery relies on to decide whether a live Deployment is backup-worthy.
 */
test('resolves an instance-suffixed PRIMARY component', function (): void {
    $match = ClusterTool::forDeployment('forgejo-git-example-com');
    expect($match['tool'])->toBe(ClusterTool::GIT)
        ->and($match['component']->key)->toBe('server');
});

test('the longest component name wins, so a WORKER is not mistaken for its primary', function (): void {
    // "forgejo-runner" is itself the runner component's own base
    // deployment name — the exact-match-first pass must resolve it directly
    // to GIT's "runner" component without ever falling through to the
    // suffix-stripping pass (which has no other tool's base name to
    // collide with here, but must not be relied on regardless).
    $match = ClusterTool::forDeployment('forgejo-runner-git-example-com');
    expect($match['tool'])->toBe(ClusterTool::GIT)
        ->and($match['component']->key)->toBe('runner');
});

test('resolves an instance-suffixed Deployment to its base component', function (): void {
    $match = ClusterTool::forDeployment('pocketbase-blog-example-com');
    expect($match['tool'])->toBe(ClusterTool::DATA)
        ->and($match['component']->key)->toBe('app');
});

test('checks every engine variant, not just the default', function (): void {
    // FLOW's default engine is n8n; a windmill instance must still resolve,
    // since it's a real, live-possible Deployment name.
    $match = ClusterTool::forDeployment('windmill-flow-example-com');
    expect($match['tool'])->toBe(ClusterTool::FLOW);
});

test('returns null for an unmanaged Deployment — the exclusion mechanism itself', function (): void {
    expect(ClusterTool::forDeployment('prometheus-server'))->toBeNull();
});

test('a compound tool\'s bundled-storage component resolves by its own exact name', function (): void {
    $match = ClusterTool::forDeployment('synapse-db-chat-example-com');
    expect($match['tool'])->toBe(ClusterTool::CHAT)
        ->and($match['component']->key)->toBe('db');
});

test('a canonical tool\'s bare, instance-less name resolves to nothing', function (): void {
    // A dead `deployment/stalwart` once aborted backup:run by claiming to be Mail.
    expect(ClusterTool::forDeployment('stalwart'))->toBeNull()
        ->and(ClusterTool::forDeployment('vaultwarden'))->toBeNull()
        ->and(ClusterTool::forDeployment('stalwart-mail-example-com'))->not->toBeNull();
});
