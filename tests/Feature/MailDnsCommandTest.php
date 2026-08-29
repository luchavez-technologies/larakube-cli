<?php

use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:dns is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:dns');
});

test('mail:dns requires installed stalwart', function (): void {
    Process::fake(['*app=mail-stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:dns', ['--zone' => 'partner.example', '--cloudflare-token' => 'tok', '--provider' => 'ses', '--ses-tokens' => 't1,t2,t3'])
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:dns configures SES Easy DKIM records using provided tokens', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // 1. GetZoneByNameRequest
        MockResponse::make(['success' => true, 'result' => [['id' => 'zone123']]]),
        // 2-4. ListDnsRecordsRequest + CreateDnsRecordRequest for 3 CNAMEs
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'rec1']]),
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'rec2']]),
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'rec3']]),
        // 5. Root SPF (List + Create/Update)
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'spf1']]),
        // 6. Custom MAIL FROM MX (List + Create/Update)
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'mx1']]),
        // 7. Custom MAIL FROM TXT (List + Create/Update)
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'spf2']]),
    ]);

    $this->artisan('mail:dns', [
        '--zone' => 'nexa-web.site',
        '--cloudflare-token' => 'cf-token',
        '--provider' => 'ses',
        '--ses-tokens' => 'token1,token2,token3',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Amazon SES DNS records published to Cloudflare for nexa-web.site');
});

test('mail:dns auto-discovers Cloudflare token from Stalwart DnsServer', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // 1. stalwartDnsServers() (call 1: query + empty get, call 2: get with ids)
        MockResponse::make([
            'methodResponses' => [
                ['x:DnsServer/query', ['ids' => ['dns1']], 'c0'],
                ['x:DnsServer/get', ['list' => [], 'notFound' => []], 'c1'],
            ],
            'sessionState' => 'x',
        ]),
        MockResponse::make([
            'methodResponses' => [
                ['x:DnsServer/get', ['list' => [
                    [
                        'id' => 'dns1',
                        'description' => 'nexa-web.site',
                        'secret' => ['@type' => 'Value', 'secret' => 'discovered-cf-token'],
                    ],
                ], 'notFound' => []], 'c1'],
            ],
            'sessionState' => 'x',
        ]),
        // 2. GetZoneByNameRequest
        MockResponse::make(['success' => true, 'result' => [['id' => 'zone123']]]),
        // 3-5. ListDnsRecordsRequest + CreateDnsRecordRequest for 3 CNAMEs
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'rec1']]),
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'rec2']]),
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'rec3']]),
        // 6. Root SPF (List + Create/Update)
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'spf1']]),
        // 7. Custom MAIL FROM MX (List + Create/Update)
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'mx1']]),
        // 8. Custom MAIL FROM TXT (List + Create/Update)
        MockResponse::make(['success' => true, 'result' => []]),
        MockResponse::make(['success' => true, 'result' => ['id' => 'spf2']]),
    ]);

    $this->artisan('mail:dns', [
        '--zone' => 'nexa-web.site',
        '--provider' => 'ses',
        '--ses-tokens' => 'tok1,tok2,tok3',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain("Auto-discovered Cloudflare API token for 'nexa-web.site' from Stalwart.")
        ->expectsOutputToContain('Amazon SES DNS records published to Cloudflare for nexa-web.site');
});
