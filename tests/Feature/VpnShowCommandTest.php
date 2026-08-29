<?php

/**
 * vpn:show surfaces two things the NetBird dashboard will not tell you:
 * how long the stored credentials have left, and whether "one company, one
 * network" still actually holds on this cluster.
 */

use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/** @return array<string, mixed> */
function vpnShowFakes(string $singleAccountLog): array
{
    return [
        '*logs deploy/vpn-management*' => Process::result(output: $singleAccountLog),
        // No PAT: the credential countdown returns early, which is exactly the
        // state in which the single-account row still has to render.
        '*get secret vpn-management-secrets*' => Process::result(output: '', exitCode: 1),
        '*larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'vpn', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'vpn.example.com'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ];
}

test('vpn:show is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('vpn:show');
});

test('vpn:show flags a broken single-account invariant', function (): void {
    Process::fake(vpnShowFakes('single account mode disabled, accounts number 4'));

    $this->artisan('vpn:show local')
        ->expectsOutputToContain('Single-account mode OFF')
        ->expectsOutputToContain('4 accounts');
});

test('vpn:show stays quiet while the invariant holds', function (): void {
    // Enabled is the expected state and :show is already dense — reporting it
    // every run would train the reader to skip the line that matters.
    Process::fake(vpnShowFakes('single account mode enabled, accounts number 1'));

    $this->artisan('vpn:show local')
        ->doesntExpectOutputToContain('Single-account mode');
});
