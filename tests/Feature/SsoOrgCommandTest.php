<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/** Common kubectl fakes every sso:org test needs — a healthy, reachable Zitadel. */
function ssoOrgBaseProcessFakes(): array
{
    return [
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
    ];
}

/** Common Zitadel/Cloudflare HTTP fakes for a happy-path onboarding (fresh org, first-try verification). */
function ssoOrgHappyPathHttpFakes(): array
{
    return [
        '*/v2/organizations/_search' => Http::response(['result' => []]),
        '*/v2/organizations' => Http::response(['organizationId' => 'org-1']),
        // Not-yet-verified by default — exercises the full DNS-challenge
        // path in the existing happy-path tests below. The "already
        // verified" skip-ahead path (see zitadelAddOrgDomain()'s docblock —
        // some PAT scopes get the domain auto-verified by Zitadel the
        // instant it's added) has its own dedicated test with this
        // overridden to isVerified: true.
        '*/orgs/me/domains/_search' => Http::response(['result' => [['domainName' => 'partner.example', 'isVerified' => false]]]),
        '*/orgs/me/domains/*/validation/_generate' => Http::response(['token' => 'zitadel-challenge-abc', 'url' => 'https://zitadel.example/docs']),
        '*/orgs/me/domains/*/validation' => Http::response([]),
        '*/orgs/me/domains' => Http::response([]),
        '*api.cloudflare.com/client/v4/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone-1']]]),
        '*api.cloudflare.com/client/v4/zones/*/dns_records*' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push(['success' => true, 'result' => ['id' => 'rec-1']]),
        '*/management/v1/actions/_search' => Http::response(['result' => []]),
        '*/management/v1/actions' => Http::response(['id' => 'action-1']),
        '*/management/v1/flows/2' => Http::response(['flow' => ['triggerActions' => []]]),
        '*/management/v1/flows/2/trigger/*' => Http::response([]),
        '*/orgs/me/members' => Http::response([]),
        '*/v2/users/human' => Http::response(['userId' => 'user-1']),
    ];
}

test('sso:org is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:org');
});

test('sso:org creates a new org, verifies the domain, and installs the RBAC action', function (): void {
    Process::fake(ssoOrgBaseProcessFakes());
    Http::fake(ssoOrgHappyPathHttpFakes());

    $this->artisan('sso:org', ['--zone' => 'partner.example', '--cloudflare-token' => 'cf-token', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('partner.example is a real Zitadel organization')
        ->expectsOutputToContain('verified');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/organizations')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST'
        && $request['name'] === 'partner.example');

    // The org header is sent on every subsequent cross-org call.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/orgs/me/domains')
        && ! str_contains($request->url(), 'validation')
        && $request->hasHeader('x-zitadel-orgid', 'org-1'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.cloudflare.com')
        && str_contains($request->url(), 'dns_records')
        && $request->method() === 'POST'
        && $request['type'] === 'TXT'
        && $request['name'] === '_zitadel-challenge.partner.example'
        && $request['content'] === 'zitadel-challenge-abc');
});

test('sso:org reuses an existing org instead of creating a duplicate', function (): void {
    Process::fake(ssoOrgBaseProcessFakes());
    Http::fake(array_merge(ssoOrgHappyPathHttpFakes(), [
        '*/v2/organizations/_search' => Http::response(['result' => [['id' => 'org-existing']]]),
    ]));

    $this->artisan('sso:org', ['--zone' => 'partner.example', '--cloudflare-token' => 'cf-token', '--force' => true])
        ->assertExitCode(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v2/organizations')
        && ! str_contains($request->url(), '_search')
        && $request->method() === 'POST');
});

test('sso:org creates an ORG_OWNER admin when --admin-email is given', function (): void {
    Process::fake(ssoOrgBaseProcessFakes());
    Http::fake(ssoOrgHappyPathHttpFakes());

    $this->artisan('sso:org', [
        '--zone' => 'partner.example',
        '--cloudflare-token' => 'cf-token',
        '--admin-email' => 'admin@partner.example',
        '--force' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('admin@partner.example');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/users/human')
        && $request['username'] === 'admin@partner.example'
        && $request['organization']['orgId'] === 'org-1');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/orgs/me/members')
        && $request['userId'] === 'user-1'
        && $request['roles'] === ['ORG_OWNER']);
});

test('sso:org skips the DNS challenge entirely when the domain is already verified', function (): void {
    // Confirmed live (2026-08-20): Zitadel auto-verifies a domain the
    // instant it's added when the calling PAT already holds instance-level
    // admin rights — asking it to generate a fresh challenge for an
    // already-verified domain fails outright ("Domain is already
    // verified", ORG-HGw21). The command must detect this and skip
    // straight to the RBAC Action + admin steps.
    Process::fake(ssoOrgBaseProcessFakes());
    Http::fake(array_merge(ssoOrgHappyPathHttpFakes(), [
        '*/orgs/me/domains/_search' => Http::response(['result' => [['domainName' => 'partner.example', 'isVerified' => true]]]),
    ]));

    $this->artisan('sso:org', ['--zone' => 'partner.example', '--cloudflare-token' => 'cf-token', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('partner.example is a real Zitadel organization')
        ->expectsOutputToContain('verified');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'validation/_generate'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.cloudflare.com'));

    // The RBAC Action install still has to run — that's the whole point of
    // not bailing out.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/management/v1/actions')
        && ! str_contains($request->url(), '_search'));
});

test('zitadelValidateOrgDomain retries on failure and succeeds once the challenge is verifiable', function (): void {
    Http::fake([
        '*/orgs/me/domains/*/validation' => Http::sequence()
            ->push(['message' => 'not verified yet'], 400)
            ->push(['message' => 'not verified yet'], 400)
            ->push([]),
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
    Http::assertSentCount(3);
});

test('zitadelValidateOrgDomain gives up after exhausting its attempts', function (): void {
    Http::fake([
        '*/orgs/me/domains/*/validation' => Http::response(['message' => 'not verified yet'], 400),
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
    Http::assertSentCount(2);
});
