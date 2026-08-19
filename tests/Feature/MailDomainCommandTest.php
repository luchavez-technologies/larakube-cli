<?php

use Illuminate\Support\Facades\Process;

/**
 * Extract and JSON-decode the JMAP payload written to a temp file by
 * stalwartJmap() — the payload rides in via `< 'tmpfile'` stdin
 * redirection, not the command string itself. Mirrors
 * MailInitStalwartStoreBootstrapTest's helper of the same shape (kept
 * separate — a global function declared in two loaded test files fatals on
 * redeclaration).
 *
 * @return array<string, mixed>|null
 */
function mailDomainJmapPayload(mixed $process): ?array
{
    $cmd = is_string($process->command)
        ? $process->command
        : implode(' ', (array) $process->command);

    // Matches the stdin redirect on ANY exec'd command, not a specific
    // temp-filename prefix — stalwartJmap()'s scratch file lives in its own
    // Spatie\TemporaryDirectory now. A non-JMAP exec's redirect falls
    // through safely via the ?: null below.
    if (preg_match("!< '([^']+)'!", $cmd, $m) && file_exists($m[1])) {
        return json_decode(file_get_contents($m[1]), true) ?: null;
    }

    return null;
}

test('mail:domain is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:domain');
});

test('mail:domain requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:domain', ['--zone' => 'partner.example', '--cloudflare-token' => 'tok', '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:domain onboards a fresh domain with automatic DNS/TLS/DKIM management', function (): void {
    $callCount = 0;
    $createDnsServerPayload = null;
    $createAcmeProviderPayload = null;
    $createDomainPayload = null;

    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function ($process) use (&$callCount, &$createDnsServerPayload, &$createAcmeProviderPayload, &$createDomainPayload) {
            $callCount++;

            return match ($callCount) {
                // 1. stalwartDnsServers() — none exist yet.
                1 => Process::result(output: '{"methodResponses":[["x:DnsServer/query",{"ids":[]},"c0"],["x:DnsServer/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                // 2. stalwartUpsertCloudflareDnsServer() creates.
                2 => tap(Process::result(output: '{"methodResponses":[["x:DnsServer/set",{"created":{"d1":{"id":"dns1"}}},"c1"]],"sessionState":"x"}'), function () use ($process, &$createDnsServerPayload): void {
                    $createDnsServerPayload = mailDomainJmapPayload($process);
                }),
                // 3. stalwartAcmeProviders() — none exist yet.
                3 => Process::result(output: '{"methodResponses":[["x:AcmeProvider/query",{"ids":[]},"c0"],["x:AcmeProvider/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                // 4. stalwartEnsureAcmeProvider() creates.
                4 => tap(Process::result(output: '{"methodResponses":[["x:AcmeProvider/set",{"created":{"a1":{"id":"acme1"}}},"c1"]],"sessionState":"x"}'), function () use ($process, &$createAcmeProviderPayload): void {
                    $createAcmeProviderPayload = mailDomainJmapPayload($process);
                }),
                // 5. stalwartDomains() — partner.example not configured yet.
                5 => Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":[]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                // 6. stalwartUpsertDomain() creates.
                6 => tap(Process::result(output: '{"methodResponses":[["x:Domain/set",{"created":{"dm1":{"id":"dom1"}}},"c1"]],"sessionState":"x"}'), function () use ($process, &$createDomainPayload): void {
                    $createDomainPayload = mailDomainJmapPayload($process);
                }),
                // 7. stalwartEnforceSingleRsaDkimSignature() — nothing to prune.
                default => Process::result(output: '{"methodResponses":[["x:DkimSignature/query",{"ids":[]},"c0"]],"sessionState":"x"}'),
            };
        },
    ]);

    $this->artisan('mail:domain', ['--zone' => 'partner.example', '--cloudflare-token' => 'cf-token-123', '--acme-email' => 'postmaster@partner.example', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('partner.example is now a fully automatic Stalwart domain')
        ->expectsOutputToContain('mail:create');

    expect($callCount)->toBe(7);

    $dnsServerCreate = $createDnsServerPayload['methodCalls'][0][1]['create']['d1'];
    expect($dnsServerCreate)->toMatchArray(['@type' => 'Cloudflare', 'description' => 'partner.example', 'secret' => ['@type' => 'Value', 'secret' => 'cf-token-123']]);

    $acmeCreate = $createAcmeProviderPayload['methodCalls'][0][1]['create']['a1'];
    expect($acmeCreate)->toMatchArray(['challengeType' => 'Dns01', 'contact' => ['mailto:postmaster@partner.example' => true]]);

    $domainCreate = $createDomainPayload['methodCalls'][0][1]['create']['dm1'];
    expect($domainCreate)->toMatchArray(['name' => 'partner.example', 'dnsManagement' => ['@type' => 'Automatic', 'dnsServerId' => 'dns1'], 'certificateManagement' => ['@type' => 'Automatic', 'acmeProviderId' => 'acme1'], 'dkimManagement' => ['@type' => 'Automatic', 'algorithms' => ['Dkim1RsaSha256' => true]]]);
});

test('mail:domain reuses an existing ACME provider for the same directory instead of creating a second one', function (): void {
    $callCount = 0;

    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;

            // A non-empty `ids` from a /query always triggers a SECOND real
            // /get(ids: [...]) call in this trait's list-fetching helpers
            // (ids:[] means "none" — the list on that first combined
            // response is never actually read) — matching the established
            // stalwartDomains()/stalwartFindRoute() two-step shape.
            return match ($callCount) {
                // 1-2. stalwartDnsServers() — an existing server for this zone.
                1 => Process::result(output: '{"methodResponses":[["x:DnsServer/query",{"ids":["dns1"]},"c0"],["x:DnsServer/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                2 => Process::result(output: '{"methodResponses":[["x:DnsServer/get",{"list":[{"id":"dns1","description":"partner.example","@type":"Cloudflare"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                // 3. stalwartUpsertCloudflareDnsServer() updates.
                3 => Process::result(output: '{"methodResponses":[["x:DnsServer/set",{"updated":{"dns1":null}},"c1"]],"sessionState":"x"}'),
                // 4-5. stalwartAcmeProviders() — an existing Let's Encrypt production provider.
                4 => Process::result(output: '{"methodResponses":[["x:AcmeProvider/query",{"ids":["acme1"]},"c0"],["x:AcmeProvider/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                5 => Process::result(output: '{"methodResponses":[["x:AcmeProvider/get",{"list":[{"id":"acme1","directory":"https:\/\/acme-v02.api.letsencrypt.org\/directory"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                // 6. stalwartDomains() — partner.example not configured yet.
                6 => Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":[]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                // 7. stalwartUpsertDomain() creates.
                7 => Process::result(output: '{"methodResponses":[["x:Domain/set",{"created":{"dm1":{"id":"dom1"}}},"c1"]],"sessionState":"x"}'),
                // 8. stalwartEnforceSingleRsaDkimSignature() — nothing to prune.
                default => Process::result(output: '{"methodResponses":[["x:DkimSignature/query",{"ids":[]},"c0"]],"sessionState":"x"}'),
            };
        },
    ]);

    $this->artisan('mail:domain', ['--zone' => 'partner.example', '--cloudflare-token' => 'cf-token-123', '--force' => true])
        ->assertExitCode(0);

    // No x:AcmeProvider/set create call — the sequence above only ever GETs it.
    expect($callCount)->toBe(8);
});
