<?php

use App\Http\Integrations\Netbird\Requests\CreateSetupKeyRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function vpnGrantKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
}

function fakeVpnGrantInstalled(string $pat = 'nbp_test_pat'): void
{
    $kubectl = vpnGrantKubectl();

    Process::fake([
        "{$kubectl} get deployment netbird-management -n larakube-vpn --no-headers" => 'netbird-management   1/1   1   1   5d',
        "{$kubectl} get secret vpn-secrets -n larakube-vpn -o jsonpath='{.data.pat}'" => Process::result(output: base64_encode($pat), exitCode: 0),
    ]);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('vpn:grant mints a single-use setup key by default and prints the join command', function (): void {
    fakeVpnGrantInstalled();

    Saloon::fake([
        CreateSetupKeyRequest::class => MockResponse::make([
            'key' => 'AAAA-BBBB-CCCC',
            'expires' => '2027-07-14T00:00:00Z',
            'usage_limit' => 1,
        ]),
    ]);

    $this->artisan('vpn:grant local --name=lloyd')
        ->assertExitCode(0)
        ->expectsOutputToContain('netbird up --management-url https://vpn.kube --setup-key AAAA-BBBB-CCCC');

    Saloon::assertSent(fn ($request, $response) => $request instanceof CreateSetupKeyRequest
        && $response->getPendingRequest()->headers()->get('Authorization') === 'Token nbp_test_pat'
        && $request->body()->get('name') === 'lloyd'
        && $request->body()->get('usage_limit') === 1);
});

test('vpn:grant --reusable mints a key with no usage limit', function (): void {
    fakeVpnGrantInstalled();

    Saloon::fake([
        CreateSetupKeyRequest::class => MockResponse::make([
            'key' => 'REUSE-ME',
            'expires' => '2027-07-14T00:00:00Z',
            'usage_limit' => 0,
        ]),
    ]);

    $this->artisan('vpn:grant local --name=lloyd --reusable')
        ->assertExitCode(0)
        ->expectsOutputToContain('REUSE-ME');

    Saloon::assertSent(fn ($request) => $request->body()->get('usage_limit') === 0);
});

test('vpn:grant errors when the VPN is not installed', function (): void {
    Process::fake([
        vpnGrantKubectl().' get deployment netbird-management -n larakube-vpn --no-headers' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('vpn:grant local --name=lloyd')
        ->assertExitCode(1)
        ->expectsOutputToContain("NetBird VPN isn't installed for 'local'");

    Saloon::assertNothingSent();
});

test('vpn:grant errors when no admin PAT has been bootstrapped yet', function (): void {
    $kubectl = vpnGrantKubectl();

    Process::fake([
        "{$kubectl} get deployment netbird-management -n larakube-vpn --no-headers" => 'netbird-management   1/1   1   1   5d',
        "{$kubectl} get secret vpn-secrets -n larakube-vpn -o jsonpath='{.data.pat}'" => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('vpn:grant local --name=lloyd')
        ->assertExitCode(1)
        ->expectsOutputToContain('No NetBird admin token found');

    Saloon::assertNothingSent();
});

test('vpn:grant --json emits a machine-readable result and no Termwind output', function (): void {
    fakeVpnGrantInstalled();

    Saloon::fake([
        CreateSetupKeyRequest::class => MockResponse::make([
            'key' => 'JSON-KEY',
            'expires' => '2027-07-14T00:00:00Z',
            'usage_limit' => 1,
        ]),
    ]);

    // Both facts must be checked as ONE substring — expectsOutputToContain()
    // only matches once per output LINE, and jsonOutput() writes the whole
    // result as a single line, so two separate checks against it collide.
    $this->artisan('vpn:grant local --name=lloyd --json')
        ->assertExitCode(0)
        ->expectsOutputToContain('"success":true,"name":"lloyd","key":"JSON-KEY"');
});
