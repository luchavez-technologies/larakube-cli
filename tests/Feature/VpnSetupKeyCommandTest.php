<?php

/**
 * vpn:setup-key stores a setup key minted elsewhere (the dashboard, for an
 * account the CLI has no PAT in) and re-enrols the in-cluster gateway with it.
 *
 * The re-enrolment half is the reason it exists: writing NB_SETUP_KEY alone
 * does nothing, because the daemon keeps the identity already on its PVC.
 */

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function vpnSetupKeyKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
}

/** @return array<string, mixed> */
function vpnSetupKeyFakes(string $kubectl, bool $installed = true): array
{
    return [
        "{$kubectl} get deployment vpn-management -n larakube-vpn*" => $installed
            ? Process::result(output: 'vpn-management 1/1 1 1 3d')
            : Process::result(output: '', exitCode: 1),
        '*patch secret vpn-management-secrets*' => Process::result(output: 'secret/vpn-management-secrets patched'),
        '*rm -f /etc/netbird/config.json*' => Process::result(output: ''),
        '*rollout restart deployment/vpn-client*' => Process::result(output: 'restarted'),
        '*rollout status deployment/vpn-client*' => Process::result(output: 'rollout success'),
    ];
}

test('vpn:setup-key stores the key and re-enrols the gateway', function (): void {
    $kubectl = vpnSetupKeyKubectl();
    Process::fake(vpnSetupKeyFakes($kubectl));

    $this->artisan('vpn:setup-key local --force --key=nbp_abc123')
        ->assertExitCode(0)
        ->expectsOutputToContain('Gateway re-enrolled');

    Process::assertRan(fn ($p) => str_contains($p->command, 'patch secret vpn-management-secrets')
        && str_contains($p->command, 'setup-key'));

    // Without removing config.json the daemon restarts onto its OLD account,
    // which looks like the command silently did nothing.
    Process::assertRan(fn ($p) => str_contains($p->command, 'rm -f /etc/netbird/config.json'));
    Process::assertRan(fn ($p) => str_contains($p->command, 'rollout restart deployment/vpn-client'));
});

test('vpn:setup-key --no-reenroll leaves the gateway alone', function (): void {
    $kubectl = vpnSetupKeyKubectl();
    Process::fake(vpnSetupKeyFakes($kubectl));

    $this->artisan('vpn:setup-key local --force --no-reenroll --key=nbp_abc123')
        ->assertExitCode(0)
        ->expectsOutputToContain('keeps its current identity');

    Process::assertRan(fn ($p) => str_contains($p->command, 'patch secret vpn-management-secrets'));
    Process::assertDidntRun(fn ($p) => str_contains($p->command, 'rm -f /etc/netbird/config.json'));
});

test('vpn:setup-key refuses to restart the gateway when the identity could not be cleared', function (): void {
    // Restarting anyway would silently re-join the OLD account — worse than
    // failing, because the command would report success.
    $kubectl = vpnSetupKeyKubectl();
    $fakes = vpnSetupKeyFakes($kubectl);
    $fakes['*rm -f /etc/netbird/config.json*'] = Process::result(output: 'error', exitCode: 1);
    Process::fake($fakes);

    $this->artisan('vpn:setup-key local --force --key=nbp_abc123')
        ->assertExitCode(1)
        ->expectsOutputToContain('OLD account');

    Process::assertDidntRun(fn ($p) => str_contains($p->command, 'rollout restart deployment/vpn-client'));
});

test('vpn:setup-key fails clearly when NetBird is not installed', function (): void {
    $kubectl = vpnSetupKeyKubectl();
    Process::fake(vpnSetupKeyFakes($kubectl, installed: false));

    $this->artisan('vpn:setup-key local --force --key=nbp_abc123')
        ->assertExitCode(1)
        ->expectsOutputToContain('NetBird is not installed');
});

test('vpn:setup-key adopts a PAT alongside the key so both point at one account', function (): void {
    $kubectl = vpnSetupKeyKubectl();
    Process::fake(vpnSetupKeyFakes($kubectl));

    $this->artisan('vpn:setup-key local --force --key=nbp_abc123 --pat=tok_xyz')
        ->assertExitCode(0);

    Process::assertRan(fn ($p) => str_contains($p->command, 'patch secret vpn-management-secrets')
        && str_contains($p->command, 'setup-key')
        && str_contains($p->command, 'pat'));
});

test('vpn:setup-key can adopt only a PAT, without touching the gateway', function (): void {
    $kubectl = vpnSetupKeyKubectl();
    Process::fake(vpnSetupKeyFakes($kubectl));

    $this->artisan('vpn:setup-key local --force --pat=tok_xyz')
        ->assertExitCode(0)
        ->expectsOutputToContain('keeps its current identity');

    Process::assertDidntRun(fn ($p) => str_contains($p->command, 'rm -f /etc/netbird/config.json'));
});

test('vpn:setup-key warns when the gateway moves account but the PAT did not', function (): void {
    // Half-adopted is the silent failure: the gateway re-homes while every CLI
    // call keeps talking to the old account.
    $kubectl = vpnSetupKeyKubectl();
    Process::fake(vpnSetupKeyFakes($kubectl));

    $this->artisan('vpn:setup-key local --force --key=nbp_abc123')
        ->assertExitCode(0)
        ->expectsOutputToContain('stored PAT was not changed');
});

test('vpn:setup-key writes the PAT through to OpenBao, not just the Secret', function (): void {
    // VpnTool declares a KV sync for `pat`, so the ExternalSecret refreshes that
    // key every 60s with creationPolicy: Merge. Patching only the Secret would be
    // quietly reverted and the command would look like it had worked.
    $kubectl = vpnSetupKeyKubectl();
    $fakes = vpnSetupKeyFakes($kubectl);
    $fakes['*get secret openbao-bootstrap*root-token*'] = Process::result(output: base64_encode('root'));
    $fakes['*get secret openbao-bootstrap*'] = Process::result(output: 'openbao-bootstrap');
    $fakes['*larakube-tools-registry*'] = Process::result(output: '');
    $fakes['*port-forward*'] = Process::result();
    Process::fake($fakes);

    Saloon::fake([
        DynamicNoBodyRequest::class => MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]),
        DynamicRequest::class => MockResponse::make(['data' => ['value' => 'ok']]),
    ]);

    $this->artisan('vpn:setup-key local --force --pat=nbp_rotated')->assertExitCode(0);

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '_PAT'));

    // The Secret still gets it too, for immediate effect and for clusters with
    // no OpenBao at all.
    Process::assertRan(fn ($p) => str_contains($p->command, 'patch secret vpn-management-secrets')
        && str_contains($p->command, 'pat'));
});
