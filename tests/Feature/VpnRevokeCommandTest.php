<?php

use App\Http\Integrations\Netbird\Requests\ListSetupKeysRequest;
use App\Http\Integrations\Netbird\Requests\UpdateSetupKeyRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

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

    Saloon::fake([
        ListSetupKeysRequest::class => MockResponse::make(vpnRevokeKeysResponse()),
        UpdateSetupKeyRequest::class => MockResponse::make(['id' => 'k1', 'revoked' => true]),
    ]);

    $this->artisan('vpn:revoke local --name=lloyd --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Revoked 'lloyd'");

    Saloon::assertSent(fn ($request) => $request instanceof UpdateSetupKeyRequest
        && $request->resolveEndpoint() === 'api/setup-keys/k1'
        && $request->body()->get('revoked') === true);
});

test('vpn:revoke --key-id revokes one specific key by id', function (): void {
    fakeVpnRevokeInstalled();

    Saloon::fake([
        ListSetupKeysRequest::class => MockResponse::make(vpnRevokeKeysResponse()),
        UpdateSetupKeyRequest::class => MockResponse::make(['id' => 'k1', 'revoked' => true]),
    ]);

    $this->artisan('vpn:revoke local --key-id=k1 --force')
        ->assertExitCode(0)
        ->expectsOutputToContain("Revoked 'lloyd'");
});

test('vpn:revoke errors when no active key exists for that name', function (): void {
    fakeVpnRevokeInstalled();

    Saloon::fake([
        ListSetupKeysRequest::class => MockResponse::make(vpnRevokeKeysResponse()),
    ]);

    // maria's only key is already revoked — nothing active left to revoke.
    $this->artisan('vpn:revoke local --name=maria --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("No active setup key found for 'maria'");
});

test('vpn:revoke without --name/--key-id in non-interactive mode errors clearly', function (): void {
    fakeVpnRevokeInstalled();

    Saloon::fake([
        ListSetupKeysRequest::class => MockResponse::make(vpnRevokeKeysResponse()),
    ]);

    $this->artisan('vpn:revoke local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Pass --name= or --key-id=');
});

test('vpn:revoke interactive picker revokes the actually-selected key, not one it crashes on or picks by accident', function (): void {
    // Regression guard for a real bug, 2026-08-25: select()'s $options was
    // built with plain 0-based integer keys ($options[$i]) — indistinguishable
    // from a list to array_is_list(), so select() returned the LABEL TEXT
    // instead of the key, and $active[$chosen] crashed with "Undefined array
    // key" on that label string. Uses two active keys (not one) specifically
    // to also prove the CORRECT one gets revoked, not just that the command
    // no longer crashes.
    fakeVpnRevokeInstalled();

    $keys = [
        ['id' => 'k1', 'name' => 'lloyd', 'key' => 'AAAA****', 'type' => 'reusable', 'usage_limit' => 1, 'used_times' => 0, 'revoked' => false, 'valid' => true, 'expires' => '2027-07-14T00:00:00Z', 'auto_groups' => [], 'ephemeral' => false, 'allow_extra_dns_labels' => false],
        ['id' => 'k3', 'name' => 'sasha', 'key' => 'CCCC****', 'type' => 'reusable', 'usage_limit' => 0, 'used_times' => 1, 'revoked' => false, 'valid' => true, 'expires' => '2027-07-14T00:00:00Z', 'auto_groups' => [], 'ephemeral' => false, 'allow_extra_dns_labels' => false],
    ];

    Saloon::fake([
        ListSetupKeysRequest::class => MockResponse::make($keys),
        UpdateSetupKeyRequest::class => MockResponse::make(['id' => 'k3', 'revoked' => true]),
    ]);

    $this->artisan('vpn:revoke local')
        ->expectsChoice('Which setup key to revoke?', 'key-k3', [
            'key-k1' => 'lloyd — AAAA**** (used 0/1)',
            'key-k3' => 'sasha — CCCC**** (used 1/∞)',
        ])
        ->expectsConfirmation("Revoke 'sasha'?", 'yes')
        ->assertExitCode(0)
        ->expectsOutputToContain("Revoked 'sasha'");

    Saloon::assertSent(fn ($request) => $request instanceof UpdateSetupKeyRequest
        && $request->resolveEndpoint() === 'api/setup-keys/k3');
});

test('vpn:revoke errors when the VPN is not installed', function (): void {
    Process::fake([
        vpnRevokeKubectl().' get deployment netbird-management -n larakube-vpn --no-headers' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('vpn:revoke local --name=lloyd --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("NetBird VPN isn't installed for 'local'");

    Saloon::assertNothingSent();
});
