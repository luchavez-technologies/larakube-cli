<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

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

test('vpn:users lists setup keys and connected peers', function () {
    fakeVpnUsersInstalled();

    Http::fake([
        'https://vpn.kube/api/setup-keys' => Http::response([
            ['id' => 'k1', 'name' => 'lloyd', 'key' => 'AAAA****', 'type' => 'reusable', 'usage_limit' => 1, 'used_times' => 0, 'revoked' => false, 'valid' => true, 'expires' => '2027-07-14T00:00:00Z'],
        ]),
        'https://vpn.kube/api/peers' => Http::response([
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

test('vpn:users shows friendly messages when nothing has been granted or joined yet', function () {
    fakeVpnUsersInstalled();

    Http::fake([
        'https://vpn.kube/api/setup-keys' => Http::response([]),
        'https://vpn.kube/api/peers' => Http::response([]),
    ]);

    $this->artisan('vpn:users local')
        ->assertExitCode(0)
        ->expectsOutputToContain('No setup keys minted yet')
        ->expectsOutputToContain('No peers have joined yet');
});

test('vpn:users errors when the VPN is not installed', function () {
    Process::fake([
        vpnUsersKubectl().' get deployment netbird-management -n larakube-vpn --no-headers' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('vpn:users local')
        ->assertExitCode(1)
        ->expectsOutputToContain("NetBird VPN isn't installed for 'local'");

    Http::assertNothingSent();
});

test('vpn:users errors when the NetBird API is unreachable', function () {
    fakeVpnUsersInstalled();

    Http::fake([
        'https://vpn.kube/api/setup-keys' => Http::response(status: 500),
    ]);

    $this->artisan('vpn:users local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Could not reach the NetBird API');
});
