<?php

use App\Http\Integrations\Cloudflare\Requests\CreateDnsRecordRequest;
use App\Http\Integrations\Cloudflare\Requests\GetZoneByNameRequest;
use App\Http\Integrations\Cloudflare\Requests\ListDnsRecordsRequest;
use App\Http\Integrations\Zitadel\Requests\AddOrgDomainRequest;
use App\Http\Integrations\Zitadel\Requests\AddOrgMemberRequest;
use App\Http\Integrations\Zitadel\Requests\CreateActionRequest;
use App\Http\Integrations\Zitadel\Requests\CreateOrganizationRequest;
use App\Http\Integrations\Zitadel\Requests\CreateUserRequest;
use App\Http\Integrations\Zitadel\Requests\GenerateOrgDomainValidationRequest;
use App\Http\Integrations\Zitadel\Requests\GetFlowRequest;
use App\Http\Integrations\Zitadel\Requests\SearchActionsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchOrganizationsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchOrgDomainsRequest;
use App\Http\Integrations\Zitadel\Requests\SetFlowTriggerActionsRequest;
use App\Http\Integrations\Zitadel\Requests\ValidateOrgDomainRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/** Common kubectl fakes every sso:org test needs — a healthy, reachable Zitadel. */
function ssoOrgBaseProcessFakes(): array
{
    return [
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ];
}

/**
 * Every endpoint sso:org touches is Saloon-based now — see
 * docs/decisions/0020-saloonphp-for-new-api-integrations.md. Not-yet-verified
 * by default — exercises the full DNS-challenge path in the existing
 * happy-path tests below. The "already verified" skip-ahead path (see
 * zitadelAddOrgDomain()'s docblock — some PAT scopes get the domain
 * auto-verified by Zitadel the instant it's added) has its own dedicated
 * test overriding SearchOrgDomainsRequest to isVerified: true.
 */
function ssoOrgSaloonFakes(): array
{
    return [
        GetZoneByNameRequest::class => MockResponse::make(['success' => true, 'result' => [['id' => 'zone-1']]]),
        ListDnsRecordsRequest::class => MockResponse::make(['success' => true, 'result' => []]),
        CreateDnsRecordRequest::class => MockResponse::make(['success' => true, 'result' => ['id' => 'rec-1']]),
        CreateUserRequest::class => MockResponse::make(['userId' => 'user-1']),
        SearchOrganizationsRequest::class => MockResponse::make(['result' => []]),
        CreateOrganizationRequest::class => MockResponse::make(['organizationId' => 'org-1']),
        SearchOrgDomainsRequest::class => MockResponse::make(['result' => [['domainName' => 'partner.example', 'isVerified' => false]]]),
        GenerateOrgDomainValidationRequest::class => MockResponse::make(['token' => 'zitadel-challenge-abc', 'url' => 'https://zitadel.example/docs']),
        ValidateOrgDomainRequest::class => MockResponse::make([]),
        AddOrgDomainRequest::class => MockResponse::make([]),
        AddOrgMemberRequest::class => MockResponse::make([]),
        SearchActionsRequest::class => MockResponse::make(['result' => []]),
        CreateActionRequest::class => MockResponse::make(['id' => 'action-1']),
        GetFlowRequest::class => MockResponse::make(['flow' => ['triggerActions' => []]]),
        SetFlowTriggerActionsRequest::class => MockResponse::make([]),
    ];
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('sso:org is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:org');
});

test('sso:org creates a new org, verifies the domain, and installs the RBAC action', function (): void {
    Process::fake(ssoOrgBaseProcessFakes());
    Saloon::fake(ssoOrgSaloonFakes());

    $this->artisan('sso:org', ['--zone' => 'partner.example', '--cloudflare-token' => 'cf-token', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('partner.example is a real Zitadel organization')
        ->expectsOutputToContain('verified');

    Saloon::assertSent(fn ($request) => $request instanceof CreateOrganizationRequest
        && $request->body()->get('name') === 'partner.example');

    // The org header is sent on every subsequent cross-org call.
    Saloon::assertSent(fn ($request, $response) => $request instanceof AddOrgDomainRequest
        && $response->getPendingRequest()->headers()->get('x-zitadel-orgid') === 'org-1');

    Saloon::assertSent(fn ($request) => $request instanceof CreateDnsRecordRequest
        && $request->body()->get('type') === 'TXT'
        && $request->body()->get('name') === '_zitadel-challenge.partner.example'
        && $request->body()->get('content') === 'zitadel-challenge-abc');
});

test('sso:org reuses an existing org instead of creating a duplicate', function (): void {
    Process::fake(ssoOrgBaseProcessFakes());
    Saloon::fake(array_merge(ssoOrgSaloonFakes(), [
        SearchOrganizationsRequest::class => MockResponse::make(['result' => [['id' => 'org-existing']]]),
    ]));

    $this->artisan('sso:org', ['--zone' => 'partner.example', '--cloudflare-token' => 'cf-token', '--force' => true])
        ->assertExitCode(0);

    Saloon::assertNotSent(CreateOrganizationRequest::class);
});

test('sso:org creates an ORG_OWNER admin when --admin-email is given', function (): void {
    Process::fake(ssoOrgBaseProcessFakes());
    Saloon::fake(ssoOrgSaloonFakes());

    $this->artisan('sso:org', [
        '--zone' => 'partner.example',
        '--cloudflare-token' => 'cf-token',
        '--admin-email' => 'admin@partner.example',
        '--force' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('admin@partner.example');

    Saloon::assertSent(fn ($request) => $request instanceof CreateUserRequest
        && $request->body()->get('username') === 'admin@partner.example'
        && $request->body()->get('organization')['orgId'] === 'org-1');

    Saloon::assertSent(fn ($request) => $request instanceof AddOrgMemberRequest
        && $request->body()->get('userId') === 'user-1'
        && $request->body()->get('roles') === ['ORG_OWNER']);
});

test('sso:org skips the DNS challenge entirely when the domain is already verified', function (): void {
    // Confirmed live (2026-08-20): Zitadel auto-verifies a domain the
    // instant it's added when the calling PAT already holds instance-level
    // admin rights — asking it to generate a fresh challenge for an
    // already-verified domain fails outright ("Domain is already
    // verified", ORG-HGw21). The command must detect this and skip
    // straight to the RBAC Action + admin steps.
    Process::fake(ssoOrgBaseProcessFakes());
    Saloon::fake(array_merge(ssoOrgSaloonFakes(), [
        SearchOrgDomainsRequest::class => MockResponse::make(['result' => [['domainName' => 'partner.example', 'isVerified' => true]]]),
    ]));

    $this->artisan('sso:org', ['--zone' => 'partner.example', '--cloudflare-token' => 'cf-token', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('partner.example is a real Zitadel organization')
        ->expectsOutputToContain('verified');

    Saloon::assertNotSent(GenerateOrgDomainValidationRequest::class);
    Saloon::assertNotSent(CreateDnsRecordRequest::class);

    // The RBAC Action install still has to run — that's the whole point of
    // not bailing out.
    Saloon::assertSent(CreateActionRequest::class);
});

test('zitadelValidateOrgDomain retries on failure and succeeds once the challenge is verifiable', function (): void {
    Saloon::fake([
        MockResponse::make(['message' => 'not verified yet'], 400),
        MockResponse::make(['message' => 'not verified yet'], 400),
        MockResponse::make([]),
    ]);

    $caller = new class
    {
        use App\Traits\InteractsWithZitadelApi;

        public function call(): bool
        {
            // attempts:3, delaySeconds:0 — fast, but still exercises the
            // real retry-then-succeed path (sso:org itself always uses the
            // slower production defaults; the command doesn't expose these
            // as options).
            return $this->zitadelValidateOrgDomain('sso.example.test', 'pat', 'org-1', 'partner.example', attempts: 3, delaySeconds: 0);
        }
    };

    expect($caller->call())->toBeTrue();
    Saloon::assertSentCount(3);
});

test('zitadelValidateOrgDomain gives up after exhausting its attempts', function (): void {
    Saloon::fake([
        ValidateOrgDomainRequest::class => MockResponse::make(['message' => 'not verified yet'], 400),
    ]);

    $caller = new class
    {
        use App\Traits\InteractsWithZitadelApi;

        public function call(): bool
        {
            return $this->zitadelValidateOrgDomain('sso.example.test', 'pat', 'org-1', 'partner.example', attempts: 2, delaySeconds: 0);
        }
    };

    expect($caller->call())->toBeFalse();
    Saloon::assertSentCount(2);
});
