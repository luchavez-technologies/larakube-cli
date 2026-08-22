<?php

use App\Http\Integrations\Netbird\Requests\ListPeersRequest;
use App\Http\Integrations\Netbird\Requests\ListSetupKeysRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function vpnUsersKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
}

function fakeVpnUsersInstalled(string $pat = 'nbp_test_pat'): void
{
    $kubectl = vpnUsersKubectl();

    Process::fake([
        "{$kubectl} get deployment netbird-management -n larakube-vpn --no-headers" => 'netbird-management   1/1   1   1   5d',
        "{$kubectl} get secret vpn-secrets -n larakube-vpn -o jsonpath='{.data.pat}'" => Process::result(output: base64_encode($pat), exitCode: 0),
    ]);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('vpn:users lists setup keys and connected peers', function (): void {
    fakeVpnUsersInstalled();

    Saloon::fake([
        ListSetupKeysRequest::class => MockResponse::make([
            ['id' => 'k1', 'name' => 'lloyd', 'key' => 'AAAA****', 'type' => 'reusable', 'usage_limit' => 1, 'used_times' => 0, 'revoked' => false, 'valid' => true, 'expires' => '2027-07-14T00:00:00Z'],
        ]),
        ListPeersRequest::class => MockResponse::make([
            ['hostname' => 'lloyds-laptop', 'ip' => '100.86.0.5', 'os' => 'macOS 14', 'connected' => true, 'last_seen' => '2026-07-14T04:00:00Z'],
        ]),
    ]);

    // Longest/most-specific substring first — expectsOutputToContain() scans
    // forward without backtracking, and 'lloyd' is a literal prefix of
    // 'lloyds-laptop', so checking it first would consume the match position
    // the longer string needs.
    $this->artisan('vpn:users local')
        ->assertExitCode(0)
        ->expectsOutputToContain('lloyds-laptop')
        ->expectsOutputToContain('lloyd');
});

test('vpn:users shows friendly messages when nothing has been granted or joined yet', function (): void {
    fakeVpnUsersInstalled();

    Saloon::fake([
        ListSetupKeysRequest::class => MockResponse::make([]),
        ListPeersRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('vpn:users local')
        ->assertExitCode(0)
        ->expectsOutputToContain('No setup keys minted yet')
        ->expectsOutputToContain('No peers have joined yet');
});

test('vpn:users errors when the VPN is not installed', function (): void {
    Process::fake([
        vpnUsersKubectl().' get deployment netbird-management -n larakube-vpn --no-headers' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('vpn:users local')
        ->assertExitCode(1)
        ->expectsOutputToContain("NetBird VPN isn't installed for 'local'");

    Saloon::assertNothingSent();
});

test('vpn:users errors when the NetBird API is unreachable', function (): void {
    fakeVpnUsersInstalled();

    Saloon::fake([
        ListSetupKeysRequest::class => MockResponse::make(status: 500),
    ]);

    $this->artisan('vpn:users local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Could not reach the NetBird API');
});
