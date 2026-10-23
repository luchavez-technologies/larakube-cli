<?php

use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function mailTlsBaseFakes(): void
{
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);
}

test('mail:tls is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:tls');
});

test('mail:tls requires installed stalwart', function (): void {
    Process::fake(['*larakube.io/tool=mail*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:tls')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:tls adds the mail host to its owning domain\'s certificate when missing', function (): void {
    mailTlsBaseFakes();

    Saloon::fake([
        // stalwartDomains(): one domain, "test" — the owner of "send.test".
        MockResponse::make(['methodResponses' => [
            ['x:Domain/query', ['ids' => ['dom1']], 'c0'],
            ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:Domain/get', ['list' => [[
                'id' => 'dom1',
                'name' => 'test',
                'certificateManagement' => [
                    '@type' => 'Automatic',
                    'acmeProviderId' => 'acme1',
                    'subjectAlternativeNames' => [],
                ],
            ]], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        // stalwartEnsureMailHostCertificate() patches the SAN set.
        MockResponse::make(['methodResponses' => [
            ['x:Domain/set', ['updated' => ['dom1' => []]], 'c1'],
        ], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:tls', ['environment' => 'local'])
        ->assertExitCode(0)
        ->expectsOutputToContain('send.test is covered by a certificate');

    Saloon::assertSentCount(3);

    Saloon::assertSent(function ($request) {
        $call = $request->body()->get('methodCalls')[0] ?? null;
        $update = json_decode(json_encode($call[1]['update']['dom1'] ?? null), true);

        return ($call[0] ?? null) === 'x:Domain/set'
            && $update['certificateManagement']['acmeProviderId'] === 'acme1'
            && $update['certificateManagement']['subjectAlternativeNames'] === ['send.test' => true];
    });
});

test('mail:tls is a no-op when the host already has a certificate', function (): void {
    mailTlsBaseFakes();

    Saloon::fake([
        MockResponse::make(['methodResponses' => [
            ['x:Domain/query', ['ids' => ['dom1']], 'c0'],
            ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:Domain/get', ['list' => [[
                'id' => 'dom1',
                'name' => 'test',
                'certificateManagement' => [
                    '@type' => 'Automatic',
                    'acmeProviderId' => 'acme1',
                    'subjectAlternativeNames' => ['send.test' => true],
                ],
            ]], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:tls', ['environment' => 'local'])
        ->assertExitCode(0)
        ->expectsOutputToContain('send.test is covered by a certificate');

    // No x:Domain/set call — nothing needed patching.
    Saloon::assertSentCount(2);
});

test('mail:tls fails clearly when the host belongs to no known domain', function (): void {
    mailTlsBaseFakes();

    Saloon::fake([
        MockResponse::make(['methodResponses' => [
            ['x:Domain/query', ['ids' => ['dom1']], 'c0'],
            ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:Domain/get', ['list' => [[
                'id' => 'dom1',
                'name' => 'unrelated.example',
                'certificateManagement' => [
                    '@type' => 'Automatic',
                    'acmeProviderId' => 'acme1',
                    'subjectAlternativeNames' => [],
                ],
            ]], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:tls', ['environment' => 'local'])
        ->assertExitCode(1)
        ->expectsOutputToContain('Could not find a domain send.test belongs to');
});
