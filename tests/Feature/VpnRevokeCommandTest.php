<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function vpnRevokeKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
}

function fakeVpnRevokeInstalled(string $pat = 'nbp_test_pat'): void
{
    $kubectl = vpnRevokeKubectl();

    Process::fake([
        "{$kubectl} get deployment netbird-management -n larakube-vpn --no-headers" => 'netbird-management   1/1   1   1   5d',
        "{$kubectl} get secret vpn-secrets -n larakube-vpn -o jsonpath='{.data.pat}'" => Process::result(output: base64_encode($pat), exitCode: 0),
    ]);
}

function vpnRevokeKeysResponse(): array
{
    return [
        ['id' => 'k1', 'name' => 'lloyd', 'key' => 'AAAA****', 'type' => 'reusable', 'usage_limit' => 1, 'used_times' => 0, 'revoked' => false, 'valid' => true, 'expires' => '2027-07-14T00:00:00Z', 'auto_groups' => [], 'ephemeral' => false, 'allow_extra_dns_labels' => false],
        ['id' => 'k2', 'name' => 'maria', 'key' => 'BBBB****', 'type' => 'reusable', 'usage_limit' => 0, 'used_times' => 3, 'revoked' => true, 'valid' => false, 'expires' => '2027-07-14T00:00:00Z', 'auto_groups' => [], 'ephemeral' => false, 'allow_extra_dns_labels' => false],
    ];
}

test('vpn:revoke --name revokes the active key for that teammate', function (): void {
    fakeVpnRevokeInstalled();

    Http::fake([
        'https://vpn.kube/api/setup-keys' => Http::response(vpnRevokeKeysResponse()),
        'https://vpn.kube/api/setup-keys/k1' => Http::response(['id' => 'k1', 'revoked' => true]),
    ]);

    $this->artisan('vpn:revoke local --name=lloyd --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Revoked 'lloyd'");

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://vpn.kube/api/setup-keys/k1'
        && $request['revoked'] === true);
});

test('vpn:revoke --key-id revokes one specific key by id', function (): void {
    fakeVpnRevokeInstalled();

    Http::fake([
        'https://vpn.kube/api/setup-keys' => Http::response(vpnRevokeKeysResponse()),
        'https://vpn.kube/api/setup-keys/k1' => Http::response(['id' => 'k1', 'revoked' => true]),
    ]);

    $this->artisan('vpn:revoke local --key-id=k1 --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Revoked 'lloyd'");
});

test('vpn:revoke errors when no active key exists for that name', function (): void {
    fakeVpnRevokeInstalled();

    Http::fake([
        'https://vpn.kube/api/setup-keys' => Http::response(vpnRevokeKeysResponse()),
    ]);

    // maria's only key is already revoked — nothing active left to revoke.
    $this->artisan('vpn:revoke local --name=maria --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("No active setup key found for 'maria'");
});

test('vpn:revoke without --name/--key-id in non-interactive mode errors clearly', function (): void {
    fakeVpnRevokeInstalled();

    Http::fake([
        'https://vpn.kube/api/setup-keys' => Http::response(vpnRevokeKeysResponse()),
    ]);

    $this->artisan('vpn:revoke local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Pass --name= or --key-id=');
});

test('vpn:revoke errors when the VPN is not installed', function (): void {
    Process::fake([
        vpnRevokeKubectl().' get deployment netbird-management -n larakube-vpn --no-headers' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('vpn:revoke local --name=lloyd --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("NetBird VPN isn't installed for 'local'");

    Http::assertNothingSent();
});
