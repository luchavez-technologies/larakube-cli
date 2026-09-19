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
    $refusesDomain = ':remove refuses --domain (the instance-aware allow-list); most also allocate fixed Commons tenants every instance shares';

    return [
        'chat' => $refusesDomain,
        'meet' => $refusesDomain,
        'design' => 'init hand-builds design-backend/-secrets/-oidc names remove never deletes',
        'desk' => $refusesDomain,
        'drive' => $refusesDomain,
        'errors' => $refusesDomain,
        'git' => $refusesDomain,
        'insights' => $refusesDomain,
        'link' => $refusesDomain,
        'mail' => $refusesDomain,
        'monitor' => $refusesDomain,
        'passwords' => $refusesDomain,
        'record' => $refusesDomain,
        'sheets' => $refusesDomain,
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
        'dns' => 'not a per-host tool: needs a Cloudflare token and manages zones',
        'notes' => 'Outline needs a login provider: without Zitadel, notes:init refuses unattended',
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

    expect($result['problems'])->toBeEmpty();
})->with(fn () => array_map(fn (ClusterTool $tool) => [$tool], ClusterTool::shippedCases()));
