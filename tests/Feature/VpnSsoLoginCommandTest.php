<?php

/**
 * vpn:sso-login brings the shared SSO account into existence.
 *
 * NetBird derives an account's email domain from the JWT that creates it, and
 * only a domained account can be shared. Neither route the operator has
 * produces one: /api/setup never sets a domain, and the dashboard hard-gates on
 * its first-run wizard when no account exists, so a browser sign-in dies at the
 * IdP without ever calling the API. The device-code grant is what lets the CLI
 * make that first call itself.
 */

use App\Http\Integrations\Netbird\Requests\CreatePersonalAccessTokenRequest;
use App\Http\Integrations\Netbird\Requests\ListAccountsRequest;
use App\Http\Integrations\Netbird\Requests\ListUsersRequest;
use App\Http\Integrations\NetbirdIdp\Requests\DeviceCodeRequest;
use App\Http\Integrations\NetbirdIdp\Requests\DeviceTokenRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/** @return array<string, mixed> */
function vpnSsoLoginFakes(): array
{
    return [
        '*get deployment vpn-management*' => Process::result(output: 'vpn-management 1/1 1 1 3d'),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*patch secret vpn-management-secrets*' => Process::result(output: 'patched'),
        '*' => Process::result(output: ''),
    ];
}

test('vpn:sso-login creates a domained account and stores its token', function (): void {
    Process::fake(vpnSsoLoginFakes());

    Saloon::fake([
        DeviceCodeRequest::class => MockResponse::make([
            'device_code' => 'dev-1',
            'user_code' => 'ABCD-EFGH',
            'verification_uri_complete' => 'https://vpn.kube/oauth2/device?user_code=ABCD-EFGH',
            'interval' => 1,
            'expires_in' => 300,
        ]),
        DeviceTokenRequest::class => MockResponse::make(['access_token' => 'jwt-abc']),
        ListUsersRequest::class => MockResponse::make([['id' => 'owner-1', 'is_current' => true]]),
        ListAccountsRequest::class => MockResponse::make([['id' => 'acc-1', 'domain' => 'kube']]),
        CreatePersonalAccessTokenRequest::class => MockResponse::make(['plain_token' => 'nbp_new']),
    ]);

    $this->artisan('vpn:sso-login local')
        ->assertExitCode(0)
        ->expectsOutputToContain('ABCD-EFGH')
        ->expectsOutputToContain("owned by the 'kube' domain");

    // The account-creating call must carry the JWT, not a PAT — a PAT belongs to
    // an account that must already exist, so it can never create one.
    Saloon::assertSent(fn ($request, $response) => $request instanceof ListUsersRequest
        && $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer jwt-abc');

    // And the CLI is wired in without anyone visiting the dashboard.
    Process::assertRan(fn ($p) => str_contains($p->command, 'patch secret vpn-management-secrets')
        && str_contains($p->command, 'pat')
        && str_contains($p->command, 'owner-pat'));
});

test('vpn:sso-login refuses to call an account without a domain a success', function (): void {
    // The exact state /api/setup leaves behind. Reporting it as done would send
    // the operator off to test SSO logins that silently fragment.
    Process::fake(vpnSsoLoginFakes());

    Saloon::fake([
        DeviceCodeRequest::class => MockResponse::make([
            'device_code' => 'dev-1', 'user_code' => 'ABCD-EFGH',
            'verification_uri_complete' => 'https://vpn.kube/oauth2/device', 'interval' => 1, 'expires_in' => 300,
        ]),
        DeviceTokenRequest::class => MockResponse::make(['access_token' => 'jwt-abc']),
        ListUsersRequest::class => MockResponse::make([['id' => 'owner-1', 'is_current' => true]]),
        ListAccountsRequest::class => MockResponse::make([['id' => 'acc-1', 'domain' => '']]),
    ]);

    $this->artisan('vpn:sso-login local')
        ->assertExitCode(1)
        ->expectsOutputToContain('without an email domain');
});

test('vpn:sso-login gives up when nobody completes the sign-in', function (): void {
    Process::fake(vpnSsoLoginFakes());

    Saloon::fake([
        DeviceCodeRequest::class => MockResponse::make([
            'device_code' => 'dev-1', 'user_code' => 'ABCD-EFGH',
            'verification_uri_complete' => 'https://vpn.kube/oauth2/device', 'interval' => 1, 'expires_in' => 60,
        ]),
        // Terminal, unlike authorization_pending — it will never become a token.
        DeviceTokenRequest::class => MockResponse::make(['error' => 'access_denied'], 400),
    ]);

    $this->artisan('vpn:sso-login local')
        ->assertExitCode(1)
        ->expectsOutputToContain('No sign-in completed');
});

test('vpn:sso-login rejects an account stamped with a domain it does not group by', function (): void {
    // The live 2026-08-29 failure: the management binary ignored the env var and
    // used its own flag default, so the account was born 'netbird.selfhosted'
    // while the cluster groups by 'kube'. Non-empty, and completely wrong — the
    // next SSO user would get a separate account and a separate /16.
    Process::fake(vpnSsoLoginFakes());

    Saloon::fake([
        DeviceCodeRequest::class => MockResponse::make([
            'device_code' => 'dev-1', 'user_code' => 'ABCD-EFGH',
            'verification_uri_complete' => 'https://vpn.kube/oauth2/device', 'interval' => 1, 'expires_in' => 300,
        ]),
        DeviceTokenRequest::class => MockResponse::make(['access_token' => 'jwt-abc']),
        ListUsersRequest::class => MockResponse::make([['id' => 'owner-1', 'is_current' => true]]),
        ListAccountsRequest::class => MockResponse::make([['id' => 'acc-1', 'domain' => 'netbird.selfhosted']]),
    ]);

    $this->artisan('vpn:sso-login local')
        ->assertExitCode(1)
        ->expectsOutputToContain("stamped 'netbird.selfhosted'")
        ->expectsOutputToContain('single-account-mode-domain');
});
