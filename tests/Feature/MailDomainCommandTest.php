<?php

use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:domain is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:domain');
});

test('mail:domain requires installed stalwart', function (): void {
    Process::fake(['*app=mail-stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:domain', ['--zone' => 'partner.example', '--cloudflare-token' => 'tok', '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:domain onboards a fresh domain with automatic DNS/TLS/DKIM management', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // 1. stalwartDnsServers() — none exist yet.
        MockResponse::make(['methodResponses' => [['x:DnsServer/query', ['ids' => []], 'c0'], ['x:DnsServer/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        // 2. stalwartUpsertCloudflareDnsServer() creates.
        MockResponse::make(['methodResponses' => [['x:DnsServer/set', ['created' => ['d1' => ['id' => 'dns1']]], 'c1']], 'sessionState' => 'x']),
        // 3. stalwartAcmeProviders() — none exist yet.
        MockResponse::make(['methodResponses' => [['x:AcmeProvider/query', ['ids' => []], 'c0'], ['x:AcmeProvider/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        // 4. stalwartEnsureAcmeProvider() creates.
        MockResponse::make(['methodResponses' => [['x:AcmeProvider/set', ['created' => ['a1' => ['id' => 'acme1']]], 'c1']], 'sessionState' => 'x']),
        // 5. stalwartDomains() — partner.example not configured yet.
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => []], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        // 6. stalwartUpsertDomain() creates.
        MockResponse::make(['methodResponses' => [['x:Domain/set', ['created' => ['dm1' => ['id' => 'dom1']]], 'c1']], 'sessionState' => 'x']),
        // 7. stalwartEnforceSingleRsaDkimSignature() — nothing to prune.
        MockResponse::make(['methodResponses' => [['x:DkimSignature/query', ['ids' => []], 'c0']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:domain', ['--zone' => 'partner.example', '--cloudflare-token' => 'cf-token-123', '--acme-email' => 'postmaster@partner.example', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('partner.example is now a fully automatic Stalwart domain')
        ->expectsOutputToContain('mail:create');

    Saloon::assertSentCount(7);

    Saloon::assertSent(function ($request) {
        $call = $request->body()->get('methodCalls')[0] ?? null;
        $create = $call[1]['create']['d1'] ?? null;

        return ($call[0] ?? null) === 'x:DnsServer/set'
            && $create['@type'] === 'Cloudflare'
            && $create['description'] === 'partner.example'
            && $create['secret'] === ['@type' => 'Value', 'secret' => 'cf-token-123'];
    });

    Saloon::assertSent(function ($request) {
        $call = $request->body()->get('methodCalls')[0] ?? null;
        $create = json_decode(json_encode($call[1]['create']['a1'] ?? null), true);

        return ($call[0] ?? null) === 'x:AcmeProvider/set'
            && $create['challengeType'] === 'Dns01'
            && $create['contact'] === ['mailto:postmaster@partner.example' => true];
    });

    Saloon::assertSent(function ($request) {
        $call = $request->body()->get('methodCalls')[0] ?? null;
        // Normalize through JSON, same as the actual wire payload — some
        // fields (dkimManagement.algorithms) are stdClass in memory, cast to
        // plain arrays by encoding/decoding, matching Stalwart's own shape.
        $create = json_decode(json_encode($call[1]['create']['dm1'] ?? null), true);

        return ($call[0] ?? null) === 'x:Domain/set'
            && $create['name'] === 'partner.example'
            && $create['dnsManagement'] === ['@type' => 'Automatic', 'dnsServerId' => 'dns1']
            && $create['certificateManagement'] === ['@type' => 'Automatic', 'acmeProviderId' => 'acme1']
            && $create['dkimManagement'] === ['@type' => 'Automatic', 'algorithms' => ['Dkim1RsaSha256' => true]];
    });
});

test('mail:domain reuses an existing ACME provider for the same directory instead of creating a second one', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    // A non-empty `ids` from a /query always triggers a SECOND real
    // /get(ids: [...]) call in this trait's list-fetching helpers (ids:[]
    // means "none" — the list on that first combined response is never
    // actually read) — matching the established stalwartDomains()/
    // stalwartFindRoute() two-step shape.
    Saloon::fake([
        // 1-2. stalwartDnsServers() — an existing server for this zone.
        MockResponse::make(['methodResponses' => [['x:DnsServer/query', ['ids' => ['dns1']], 'c0'], ['x:DnsServer/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:DnsServer/get', ['list' => [['id' => 'dns1', 'description' => 'partner.example', '@type' => 'Cloudflare']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        // 3. stalwartUpsertCloudflareDnsServer() updates.
        MockResponse::make(['methodResponses' => [['x:DnsServer/set', ['updated' => ['dns1' => null]], 'c1']], 'sessionState' => 'x']),
        // 4-5. stalwartAcmeProviders() — an existing Let's Encrypt production provider.
        MockResponse::make(['methodResponses' => [['x:AcmeProvider/query', ['ids' => ['acme1']], 'c0'], ['x:AcmeProvider/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:AcmeProvider/get', ['list' => [['id' => 'acme1', 'directory' => 'https://acme-v02.api.letsencrypt.org/directory']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        // 6. stalwartDomains() — partner.example not configured yet.
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => []], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        // 7. stalwartUpsertDomain() creates.
        MockResponse::make(['methodResponses' => [['x:Domain/set', ['created' => ['dm1' => ['id' => 'dom1']]], 'c1']], 'sessionState' => 'x']),
        // 8. stalwartEnforceSingleRsaDkimSignature() — nothing to prune.
        MockResponse::make(['methodResponses' => [['x:DkimSignature/query', ['ids' => []], 'c0']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:domain', ['--zone' => 'partner.example', '--cloudflare-token' => 'cf-token-123', '--force' => true])
        ->assertExitCode(0);

    // No x:AcmeProvider/set create call — the sequence above only ever GETs it.
    Saloon::assertSentCount(8);
    Saloon::assertNotSent(fn ($request) => ($request->body()->get('methodCalls')[0][0] ?? null) === 'x:AcmeProvider/set');
});
