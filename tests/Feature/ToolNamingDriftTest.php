<?php

use App\Enums\ClusterTool;
use Saloon\Http\Faking\MockClient;
use Tests\Support\ToolDriftHarness;

/**
 * Every Cluster Tool's `:init` and `:remove --purge` must agree on every name
 * (ADR 0021): installing instance A and B, then removing A, must delete all
 * of A's resources and Commons tenants and nothing of B's.
 *
 * Both lists below may only shrink. A tool that starts passing fails this
 * test until it's taken off its list, so fixes are locked in.
 */
function toolNamingKnownDrift(): array
{
    $refusesDomain = ':remove refuses --domain (the instance-aware allow-list), which hides any other drift';

    return [
        'chat' => $refusesDomain,
        'meet' => $refusesDomain,
        'crm' => 'Redis tenant allocated as crm_twenty_<instance> is never freed by purge',
        'design' => 'init hand-builds design-backend/-secrets/-oidc names remove never deletes; Commons tenants not freed',
        'drive' => $refusesDomain,
        'errors' => $refusesDomain,
        'flow' => $refusesDomain,
        'git' => $refusesDomain,
        'insights' => $refusesDomain,
        'link' => $refusesDomain,
        'mail' => $refusesDomain,
        'monitor' => $refusesDomain,
        'passwords' => $refusesDomain,
        'record' => $refusesDomain,
        'sheets' => $refusesDomain,
        'sign' => $refusesDomain,
        'sso' => $refusesDomain,
        'support' => $refusesDomain,
        'tasks' => $refusesDomain,
        'vpn' => $refusesDomain,
        'dashboard' => $refusesDomain,
        'resume' => $refusesDomain,
    ];
}

/** Tools the harness can't drive yet, and why. */
function toolNamingHarnessPending(): array
{
    return [
        'desk' => 'desk:init calls flagOrPrompt(), which DeskInitCommand does not have',
        'dns' => 'not a per-host tool: needs a Cloudflare token and manages zones',
        'notes' => 'notes:init crashes non-interactively (select() without a default returns null)',
        'secrets' => 'OpenBao init talks to its HTTP API',
        'webmail' => 'needs Mail installed first',
    ];
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('init and remove agree on every name', function (ClusterTool $tool): void {
    $result = ToolDriftHarness::check($tool);
    $pending = toolNamingHarnessPending()[$tool->value] ?? null;
    $knownDrift = toolNamingKnownDrift()[$tool->value] ?? null;

    if ($pending !== null) {
        expect($result['harnessed'])->toBeFalse("{$tool->value} now runs in the harness: take it off toolNamingHarnessPending().");

        return;
    }

    expect($result['harnessed'])->toBeTrue("{$tool->value}: {$result['reason']}");

    if ($knownDrift !== null) {
        expect($result['problems'])->not->toBeEmpty("{$tool->value} no longer drifts: take it off toolNamingKnownDrift().");

        return;
    }

    expect($result['problems'])->toBe([]);
})->with(fn () => array_map(fn (ClusterTool $tool) => [$tool], ClusterTool::shippedCases()));
