<?php

use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * Fake JMAP transport for mail:dkim. Reading signatures costs four JMAP
 * calls in this order: DkimSignature/query, DkimSignature/get, then
 * Domain/query+get (one request) and a second Domain/get to resolve names
 * for the ids returned.
 *
 * @param  list<array{id: string, type: string, stage: string}>  $signatures
 * @return list<MockResponse>
 */
function dkimJmapSequence(array $signatures): array
{
    $ids = array_map(fn (array $s) => $s['id'], $signatures);

    return [
        MockResponse::make([
            'methodResponses' => [['x:DkimSignature/query', ['ids' => $ids], 'c0']],
        ]),
        MockResponse::make([
            'methodResponses' => [['x:DkimSignature/get', ['list' => array_map(fn (array $s) => [
                'id' => $s['id'],
                'domainId' => 'dom-1',
                'selector' => 'v1-'.$s['id'],
                '@type' => $s['type'],
                'stage' => $s['stage'],
            ], $signatures)], 'c1']],
        ]),
        MockResponse::make([
            'methodResponses' => [
                ['x:Domain/query', ['ids' => ['dom-1']], 'c0'],
                ['x:Domain/get', ['list' => []], 'c1'],
            ],
        ]),
        MockResponse::make([
            'methodResponses' => [['x:Domain/get', ['list' => [['id' => 'dom-1', 'name' => 'luchtech.dev']]], 'c1']],
        ]),
    ];
}

test('mail:dkim is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:dkim');
});

test('mail:dkim requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:dkim')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:dkim fails and points at --fix when a domain has two active keys', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake(dkimJmapSequence([
        ['id' => 'sig-rsa', 'type' => 'Dkim1RsaSha256', 'stage' => 'active'],
        ['id' => 'sig-ed', 'type' => 'Dkim1Ed25519Sha256', 'stage' => 'active'],
    ]));

    $this->artisan('mail:dkim')
        ->assertExitCode(1)
        ->expectsOutputToContain('luchtech.dev has 2 active signing keys')
        ->expectsOutputToContain('--fix');
});

test('mail:dkim passes when a single active key signs the domain', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake(dkimJmapSequence([
        ['id' => 'sig-rsa', 'type' => 'Dkim1RsaSha256', 'stage' => 'active'],
    ]));

    $this->artisan('mail:dkim')
        ->assertExitCode(0)
        ->expectsOutputToContain('single active key');
});

test('mail:dkim --fix destroys the Ed25519 key and reports the count', function (): void {
    // --fix prunes first, then re-reads, so the destroy response is spliced in
    // ahead of a second full read that now shows RSA only.
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // Read for the prune: one RSA + one Ed25519.
        MockResponse::make(['methodResponses' => [['x:DkimSignature/query', ['ids' => ['sig-rsa', 'sig-ed']], 'c0']]]),
        MockResponse::make(['methodResponses' => [['x:DkimSignature/get', ['list' => [
            ['id' => 'sig-rsa', 'domainId' => 'dom-1', 'selector' => 'v1-rsa', '@type' => 'Dkim1RsaSha256', 'stage' => 'active'],
            ['id' => 'sig-ed', 'domainId' => 'dom-1', 'selector' => 'v1-ed', '@type' => 'Dkim1Ed25519Sha256', 'stage' => 'active'],
        ]], 'c1']]]),
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['dom-1']], 'c0'], ['x:Domain/get', ['list' => []], 'c1']]]),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'dom-1', 'name' => 'luchtech.dev']]], 'c1']]]),
        // The destroy itself.
        MockResponse::make(['methodResponses' => [['x:DkimSignature/set', ['destroyed' => ['sig-ed']], 'c2']]]),
        // Re-read for the table: RSA only now.
        MockResponse::make(['methodResponses' => [['x:DkimSignature/query', ['ids' => ['sig-rsa']], 'c0']]]),
        MockResponse::make(['methodResponses' => [['x:DkimSignature/get', ['list' => [
            ['id' => 'sig-rsa', 'domainId' => 'dom-1', 'selector' => 'v1-rsa', '@type' => 'Dkim1RsaSha256', 'stage' => 'active'],
        ]], 'c1']]]),
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['dom-1']], 'c0'], ['x:Domain/get', ['list' => []], 'c1']]]),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'dom-1', 'name' => 'luchtech.dev']]], 'c1']]]),
    ]);

    $this->artisan('mail:dkim', ['--fix' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Removed 1 Ed25519 signing key')
        ->expectsOutputToContain('single active key');
});
