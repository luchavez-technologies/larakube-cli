<?php

use App\Exceptions\MissingFlagException;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function mailDomainDeleteBaseFakes(): void
{
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);
}

/** The x:Domain/query+get pair stalwartDomains() always makes, as two MockResponses. */
function mailDomainDeleteDomainsFound(): array
{
    return [
        MockResponse::make(['methodResponses' => [
            ['x:Domain/query', ['ids' => ['dom1']], 'c0'],
            ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:Domain/get', ['list' => [[
                'id' => 'dom1',
                'name' => 'partner.example',
                'dnsManagement' => ['@type' => 'Automatic', 'dnsServerId' => 'dns1'],
                'certificateManagement' => ['@type' => 'Automatic', 'acmeProviderId' => 'acme1'],
            ]], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
    ];
}

test('mail:domain-delete is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:domain-delete');
});

test('mail:domain-delete requires installed stalwart', function (): void {
    Process::fake(['*larakube.io/tool=mail*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:domain-delete', ['--domain' => 'partner.example', '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:domain-delete requires --domain when it cannot prompt', function (): void {
    mailDomainDeleteBaseFakes();

    $this->artisan('mail:domain-delete', ['--force' => true]);
})->throws(MissingFlagException::class, 'Missing required --domain');

test('mail:domain-delete errors on an unconfigured domain', function (): void {
    mailDomainDeleteBaseFakes();

    Saloon::fake([
        MockResponse::make(['methodResponses' => [
            ['x:Domain/query', ['ids' => []], 'c0'],
            ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:domain-delete', ['--domain' => 'ghost.example', '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("Domain 'ghost.example' is not configured");
});

test('mail:domain-delete removes accounts, DKIM signatures, the domain, and its DnsServer, in order', function (): void {
    mailDomainDeleteBaseFakes();

    Saloon::fake([
        // 1-2. stalwartDomains() — the lookup in the command itself.
        ...mailDomainDeleteDomainsFound(),
        // 3. stalwartAccounts() query — 3 accounts, 2 on partner.example.
        MockResponse::make(['methodResponses' => [
            ['x:Account/query', ['ids' => ['a1', 'a2', 'a3']], 'c0'],
            ['x:Account/get', ['list' => [], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        // 4. stalwartAccounts() get.
        MockResponse::make(['methodResponses' => [
            ['x:Account/get', ['list' => [
                ['id' => 'a1', 'emailAddress' => 'alice@partner.example', 'name' => 'alice', 'description' => 'Alice'],
                ['id' => 'a2', 'emailAddress' => 'bob@partner.example', 'name' => 'bob', 'description' => 'Bob'],
                ['id' => 'a3', 'emailAddress' => 'carol@other.example', 'name' => 'carol', 'description' => 'Carol'],
            ], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        // 5. stalwartDkimSignatures() query — 2 signatures.
        MockResponse::make(['methodResponses' => [
            ['x:DkimSignature/query', ['ids' => ['sig1', 'sig2']], 'c0'],
        ], 'sessionState' => 'x']),
        // 6. stalwartDkimSignatures() get.
        MockResponse::make(['methodResponses' => [
            ['x:DkimSignature/get', ['list' => [
                ['id' => 'sig1', 'domainId' => 'dom1', 'selector' => 'v1-rsa', '@type' => 'Dkim1RsaSha256', 'stage' => 'active'],
                ['id' => 'sig2', 'domainId' => 'dom1', 'selector' => 'v2-rsa', '@type' => 'Dkim1RsaSha256', 'stage' => 'active'],
            ], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        // 7-8. stalwartDkimSignatures()'s own internal stalwartDomains() re-resolution.
        ...mailDomainDeleteDomainsFound(),
        // 9. stalwartDestroyAccounts() — only a1/a2, never a3.
        MockResponse::make(['methodResponses' => [
            ['x:Account/set', ['destroyed' => ['a1', 'a2']], 'c1'],
        ], 'sessionState' => 'x']),
        // 10. stalwartDestroyDkimSignatures().
        MockResponse::make(['methodResponses' => [
            ['x:DkimSignature/set', ['destroyed' => ['sig1', 'sig2']], 'c2'],
        ], 'sessionState' => 'x']),
        // 11. stalwartDestroyDomain().
        MockResponse::make(['methodResponses' => [
            ['x:Domain/set', ['destroyed' => ['dom1']], 'c1'],
        ], 'sessionState' => 'x']),
        // 12. stalwartDestroyDnsServer().
        MockResponse::make(['methodResponses' => [
            ['x:DnsServer/set', ['destroyed' => ['dns1']], 'c1'],
        ], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:domain-delete', ['--domain' => 'partner.example', '--force' => true])
        ->expectsOutputToContain('2 mailbox account(s)')
        ->expectsOutputToContain('2 DKIM signature(s)')
        ->expectsOutputToContain('fully removed from Stalwart')
        ->expectsOutputToContain('Removed 2 mailbox(es) and 2 key(s)')
        ->expectsOutputToContain('left alone')
        ->assertExitCode(0);

    Saloon::assertSentCount(12);

    Saloon::assertSent(function ($request) {
        $call = $request->body()->get('methodCalls')[0] ?? null;

        return ($call[0] ?? null) === 'x:Account/set'
            && ($call[1]['destroy'] ?? null) === ['a1', 'a2'];
    });

    Saloon::assertSent(function ($request) {
        $call = $request->body()->get('methodCalls')[0] ?? null;

        return ($call[0] ?? null) === 'x:DkimSignature/set'
            && ($call[1]['destroy'] ?? null) === ['sig1', 'sig2'];
    });

    Saloon::assertSent(function ($request) {
        $call = $request->body()->get('methodCalls')[0] ?? null;

        return ($call[0] ?? null) === 'x:Domain/set'
            && ($call[1]['destroy'] ?? null) === ['dom1'];
    });

    Saloon::assertSent(function ($request) {
        $call = $request->body()->get('methodCalls')[0] ?? null;

        return ($call[0] ?? null) === 'x:DnsServer/set'
            && ($call[1]['destroy'] ?? null) === ['dns1'];
    });
});

test('mail:domain-delete reports why an account destroy was refused, not just a count', function (): void {
    // Confirmed live (2026-10-24): destroying a batch mixing Individual and
    // Group-type accounts destroyed every Individual and refused every Group
    // — the operator only ever saw "Only 7/14 were destroyed," with no way to
    // tell why without reading the JMAP response by hand.
    mailDomainDeleteBaseFakes();

    Saloon::fake([
        ...mailDomainDeleteDomainsFound(),
        MockResponse::make(['methodResponses' => [
            ['x:Account/query', ['ids' => ['a1', 'a2']], 'c0'],
            ['x:Account/get', ['list' => [], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:Account/get', ['list' => [
                ['id' => 'a1', 'emailAddress' => 'alice@partner.example', 'name' => 'alice', 'description' => 'Alice'],
                ['id' => 'a2', 'emailAddress' => 'support@partner.example', 'name' => 'support', 'description' => null],
            ], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        // stalwartDkimSignatures() — none, fetched unconditionally before confirmation.
        MockResponse::make(['methodResponses' => [
            ['x:DkimSignature/query', ['ids' => []], 'c0'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:Account/set', [
                'destroyed' => ['a1'],
                'notDestroyed' => ['a2' => ['type' => 'forbidden', 'description' => 'account is a Group and cannot be destroyed this way']],
            ], 'c1'],
        ], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:domain-delete', ['--domain' => 'partner.example', '--force' => true])
        ->expectsOutputToContain('Only 1/2')
        ->expectsOutputToContain('support@partner.example')
        ->expectsOutputToContain('forbidden: account is a Group')
        ->assertExitCode(1);

    // DKIM/domain/DNS were never touched — the abort happened before them.
    Saloon::assertSentCount(6);
    Saloon::assertNotSent(fn ($request) => in_array($request->body()->get('methodCalls')[0][0] ?? null, ['x:DkimSignature/set', 'x:Domain/set', 'x:DnsServer/set'], true));
});

test('mail:domain-delete aborts when the retyped domain name does not match', function (): void {
    mailDomainDeleteBaseFakes();

    Saloon::fake([
        ...mailDomainDeleteDomainsFound(),
        // stalwartAccounts(): none.
        MockResponse::make(['methodResponses' => [
            ['x:Account/query', ['ids' => []], 'c0'],
            ['x:Account/get', ['list' => [], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        // stalwartDkimSignatures(): none.
        MockResponse::make(['methodResponses' => [
            ['x:DkimSignature/query', ['ids' => []], 'c0'],
        ], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:domain-delete', ['--domain' => 'partner.example'])
        ->expectsQuestion("To confirm, type the domain name 'partner.example':", 'not-the-right-domain')
        ->expectsOutputToContain('Confirmation failed')
        ->assertExitCode(1);

    // No destroy call of any kind was ever sent.
    Saloon::assertNotSent(function ($request) {
        $call = $request->body()->get('methodCalls')[0] ?? null;

        return is_array($call) && array_key_exists('destroy', $call[1] ?? []);
    });
});

test('mail:domain-delete falls back to soft-disabling the domain when x:Domain/set destroy is not supported', function (): void {
    mailDomainDeleteBaseFakes();

    Saloon::fake([
        ...mailDomainDeleteDomainsFound(),
        MockResponse::make(['methodResponses' => [
            ['x:Account/query', ['ids' => []], 'c0'],
            ['x:Account/get', ['list' => [], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:DkimSignature/query', ['ids' => []], 'c0'],
        ], 'sessionState' => 'x']),
        // Destroy "succeeds" (non-null response) but the id never appears destroyed.
        MockResponse::make(['methodResponses' => [
            ['x:Domain/set', ['destroyed' => [], 'notDestroyed' => ['dom1' => ['type' => 'notAllowed']]], 'c1'],
        ], 'sessionState' => 'x']),
        // Soft-disable fallback.
        MockResponse::make(['methodResponses' => [
            ['x:Domain/set', ['updated' => ['dom1' => []]], 'c1'],
        ], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:domain-delete', ['--domain' => 'partner.example', '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('may not support x:Domain/set destroy')
        ->expectsOutputToContain("Fell back to disabling 'partner.example'");

    Saloon::assertSent(function ($request) {
        $call = $request->body()->get('methodCalls')[0] ?? null;
        $update = json_decode(json_encode($call[1]['update']['dom1'] ?? null), true);

        return ($call[0] ?? null) === 'x:Domain/set'
            && ($update['isEnabled'] ?? null) === false;
    });

    // The DnsServer destroy call never happened — we never get that far.
    Saloon::assertNotSent(fn ($request) => ($request->body()->get('methodCalls')[0][0] ?? null) === 'x:DnsServer/set');
});

test('mail:domain-delete reports an orphaned DnsServer when its destroy fails after the domain succeeds', function (): void {
    mailDomainDeleteBaseFakes();

    Saloon::fake([
        ...mailDomainDeleteDomainsFound(),
        MockResponse::make(['methodResponses' => [
            ['x:Account/query', ['ids' => []], 'c0'],
            ['x:Account/get', ['list' => [], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:DkimSignature/query', ['ids' => []], 'c0'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:Domain/set', ['destroyed' => ['dom1']], 'c1'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:DnsServer/set', ['destroyed' => []], 'c1'],
        ], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:domain-delete', ['--domain' => 'partner.example', '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('is now orphaned');
});
