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
        'matrix' => $refusesDomain,
        'meet' => $refusesDomain,
        'livekit' => $refusesDomain,
        'drive' => $refusesDomain,
        'ocis' => $refusesDomain,
        'git' => $refusesDomain,
        'forgejo' => $refusesDomain,
        'mail' => $refusesDomain,
        'stalwart' => $refusesDomain,
        'monitor' => $refusesDomain,
        'grafana' => $refusesDomain,
        'passwords' => $refusesDomain,
        'vaultwarden' => $refusesDomain,
        'sso' => $refusesDomain,
        'zitadel' => $refusesDomain,
        'vpn' => $refusesDomain,
        'netbird' => $refusesDomain,
        'dashboard' => $refusesDomain,
        'headlamp' => $refusesDomain,
    ];
}

/** Tools the harness can't drive yet, and why. */
function toolNamingHarnessPending(): array
{
    return [
        'dns' => 'not a per-host tool: needs a Cloudflare token and manages zones',
        'external-dns' => 'not a per-host tool: needs a Cloudflare token and manages zones',
        'notes' => 'Outline needs a login provider: without Zitadel, tool:init --tool=outline refuses unattended',
        'outline' => 'Outline needs a login provider: without Zitadel, tool:init --tool=outline refuses unattended',
        'secrets' => 'OpenBao init talks to its HTTP API',
        'openbao' => 'OpenBao init talks to its HTTP API',
        'webmail' => 'needs Mail installed first',
        'bulwark' => 'needs Mail installed first',
    ];
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('init and remove agree on every name', function (ClusterTool $tool): void {
    $result = ToolDriftHarness::check($tool);
    $pending = toolNamingHarnessPending()[$tool->value] ?? toolNamingHarnessPending()[$tool->legacyCategoryPrefix() ?? ''] ?? null;
    $knownDrift = toolNamingKnownDrift()[$tool->value] ?? toolNamingKnownDrift()[$tool->legacyCategoryPrefix() ?? ''] ?? null;

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
