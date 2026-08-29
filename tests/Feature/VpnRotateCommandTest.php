<?php

/**
 * vpn:rotate replaces the stored NetBird PAT + setup key BEFORE they expire.
 *
 * It matters because vpn:init mints both in one call, so they expire within
 * milliseconds of each other — once the PAT is dead it cannot mint its own
 * replacement, and recovery is manual. This command only works while the
 * current PAT is still valid, which is precisely why it must not half-apply.
 */

use App\Http\Integrations\Netbird\Requests\CreatePersonalAccessTokenRequest;
use App\Http\Integrations\Netbird\Requests\CreateSetupKeyRequest;
use App\Http\Integrations\Netbird\Requests\ListUsersRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function vpnRotateKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
}

/** @return array<string, mixed> */
function vpnRotateFakes(string $kubectl): array
{
    return [
        "{$kubectl} get deployment vpn-management -n larakube-vpn*" => Process::result(output: 'vpn-management 1/1 1 1 3d'),
        '*data.pat*' => Process::result(output: base64_encode('old-pat')),
        '*patch secret vpn-management-secrets*' => Process::result(output: 'secret/vpn-management-secrets patched'),
    ];
}

test('vpn:rotate mints a new PAT and setup key and stores both', function (): void {
    $kubectl = vpnRotateKubectl();
    Process::fake(vpnRotateFakes($kubectl));

    Saloon::fake([
        ListUsersRequest::class => MockResponse::make([['id' => 'user-1', 'is_current' => true]]),
        CreatePersonalAccessTokenRequest::class => MockResponse::make(['plain_token' => 'nbp_new']),
        CreateSetupKeyRequest::class => MockResponse::make(['key' => 'key_new']),
    ]);

    $this->artisan('vpn:rotate local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('credentials rotated');

    Process::assertRan(fn ($p) => str_contains($p->command, 'patch secret vpn-management-secrets')
        && str_contains($p->command, 'pat')
        && str_contains($p->command, 'setup-key'));
});

test('vpn:rotate mints the setup key with the NEW pat, proving it works before storing', function (): void {
    $kubectl = vpnRotateKubectl();
    Process::fake(vpnRotateFakes($kubectl));

    Saloon::fake([
        ListUsersRequest::class => MockResponse::make([['id' => 'user-1', 'is_current' => true]]),
        CreatePersonalAccessTokenRequest::class => MockResponse::make(['plain_token' => 'nbp_new']),
        CreateSetupKeyRequest::class => MockResponse::make(['key' => 'key_new']),
    ]);

    $this->artisan('vpn:rotate local --force')->assertExitCode(0);

    Saloon::assertSent(fn ($request, $response) => $request instanceof CreateSetupKeyRequest
        && $response->getPendingRequest()->headers()->get('Authorization') === 'Token nbp_new');
});

test('vpn:rotate leaves the old credentials in place when the setup key cannot be minted', function (): void {
    // Half-rotating is the dangerous outcome: the stored PAT would be replaced
    // by one that has not been proven to work.
    $kubectl = vpnRotateKubectl();
    Process::fake(vpnRotateFakes($kubectl));

    Saloon::fake([
        ListUsersRequest::class => MockResponse::make([['id' => 'user-1', 'is_current' => true]]),
        CreatePersonalAccessTokenRequest::class => MockResponse::make(['plain_token' => 'nbp_new']),
        CreateSetupKeyRequest::class => MockResponse::make(['message' => 'nope'], 403),
    ]);

    $this->artisan('vpn:rotate local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('credentials Secret is unchanged');

    Process::assertDidntRun(fn ($p) => str_contains($p->command, 'patch secret vpn-management-secrets'));
});

test('vpn:rotate says how to recover when the stored PAT is already dead', function (): void {
    $kubectl = vpnRotateKubectl();
    Process::fake(vpnRotateFakes($kubectl));

    Saloon::fake([
        ListUsersRequest::class => MockResponse::make(['message' => 'unauthorized'], 401),
    ]);

    $this->artisan('vpn:rotate local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('may already be expired');
});

test('vpn:rotate finds the larakube-cli service user when NetBird does not flag it as current', function (): void {
    // vpn:init now hangs the PAT off a service user. If a NetBird release ever
    // stops setting is_current for service-user tokens, the difference is
    // vpn:rotate renewing the PAT vs a hard lockout on day 365.
    $kubectl = vpnRotateKubectl();
    Process::fake(vpnRotateFakes($kubectl));

    Saloon::fake([
        ListUsersRequest::class => MockResponse::make([
            ['id' => 'human-1', 'name' => 'James', 'is_service_user' => false, 'is_current' => false],
            ['id' => 'svc-1', 'name' => 'larakube-cli', 'is_service_user' => true, 'is_current' => false],
        ]),
        CreatePersonalAccessTokenRequest::class => MockResponse::make(['plain_token' => 'nbp_new']),
        CreateSetupKeyRequest::class => MockResponse::make(['key' => 'key_new']),
    ]);

    $this->artisan('vpn:rotate local --force')->assertExitCode(0);

    Saloon::assertSent(fn ($request) => $request instanceof CreatePersonalAccessTokenRequest
        && str_contains($request->resolveEndpoint(), 'svc-1'));
});

test('vpn:rotate points at the dashboard recovery path when no user can be resolved', function (): void {
    $kubectl = vpnRotateKubectl();
    Process::fake(vpnRotateFakes($kubectl));

    Saloon::fake([
        ListUsersRequest::class => MockResponse::make([]),
    ]);

    $this->artisan('vpn:rotate local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('vpn:setup-key');
});
