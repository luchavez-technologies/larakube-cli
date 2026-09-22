<?php

use App\Http\Integrations\Cloudflare\Requests\GetZoneSettingRequest;
use App\Http\Integrations\Cloudflare\Requests\ListZonesRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function tlsShowFakes(bool $dnsChallenge, bool $proxied, string $storedCerts = ''): array
{
    $annotations = ['traefik.ingress.kubernetes.io/router.tls.certresolver' => 'letsencrypt'];
    if ($proxied) {
        $annotations['external-dns.alpha.kubernetes.io/cloudflare-proxied'] = 'true';
    }

    return [
        '*get secret traefik-acme-cloudflare -n traefik -o name*' => Process::result(output: $dnsChallenge ? 'secret/traefik-acme-cloudflare' : ''),
        '*get secret traefik-acme-cloudflare -n traefik -o jsonpath*' => Process::result(output: base64_encode('cf-token-123')),
        '*get ingress -A -o json*' => Process::result(output: (string) json_encode(['items' => [
            ['metadata' => ['namespace' => 'apps', 'name' => 'web', 'annotations' => $annotations], 'spec' => ['rules' => [['host' => 'app.example.com']]]],
            ['metadata' => ['namespace' => 'apps', 'name' => 'shop', 'annotations' => ['traefik.ingress.kubernetes.io/router.tls.certresolver' => 'letsencrypt']], 'spec' => ['rules' => [['host' => 'shop.elsewhere.net']]]],
        ]])),
        '*exec -n traefik deploy/traefik*' => Process::result(output: $storedCerts),
        '*' => Process::result(output: ''),
    ];
}

test('on the HTTP challenge, tls:show flags proxied hosts that can\'t renew', function (): void {
    Process::fake(tlsShowFakes(dnsChallenge: false, proxied: true));

    $this->artisan('tls:show production --context=ctx')
        ->expectsOutputToContain('HTTP')
        ->expectsOutputToContain('app.example.com')
        ->expectsOutputToContain('larakube tls:init production')
        ->assertExitCode(0);
});

test('on the DNS challenge, tls:show flags hosts outside the token\'s zones', function (): void {
    Process::fake(tlsShowFakes(dnsChallenge: true, proxied: true));
    Saloon::fake([
        ListZonesRequest::class => MockResponse::make([
            'success' => true,
            'result' => [['id' => 'zone-1', 'name' => 'example.com']],
            'result_info' => ['total_pages' => 1],
        ]),
        GetZoneSettingRequest::class => MockResponse::make([
            'success' => true,
            'result' => ['value' => 'strict'],
        ]),
    ]);

    $this->artisan('tls:show production --context=ctx')
        ->expectsOutputToContain('Cloudflare DNS')
        ->expectsOutputToContain('shop.elsewhere.net')
        ->doesntExpectOutputToContain('Every Let\'s Encrypt host can renew')
        ->assertExitCode(0);

    // Reading the token back never puts it on a command line.
    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'cf-token-123'));
});

test('on the DNS challenge, tls:show reports Full (strict) when the zone setting is strict', function (): void {
    Process::fake(tlsShowFakes(dnsChallenge: true, proxied: false));
    Saloon::fake([
        ListZonesRequest::class => MockResponse::make([
            'success' => true,
            'result' => [['id' => 'zone-1', 'name' => 'example.com']],
            'result_info' => ['total_pages' => 1],
        ]),
        GetZoneSettingRequest::class => MockResponse::make([
            'success' => true,
            'result' => ['value' => 'strict'],
        ]),
    ]);

    $this->artisan('tls:show production --context=ctx')
        ->expectsOutputToContain('Full (strict) ✓')
        ->expectsOutputToContain('example.com')
        ->assertExitCode(0);
});

test('on the DNS challenge, tls:show reports Full and recommends switching to strict', function (): void {
    Process::fake(tlsShowFakes(dnsChallenge: true, proxied: false));
    Saloon::fake([
        ListZonesRequest::class => MockResponse::make([
            'success' => true,
            'result' => [['id' => 'zone-1', 'name' => 'example.com']],
            'result_info' => ['total_pages' => 1],
        ]),
        GetZoneSettingRequest::class => MockResponse::make([
            'success' => true,
            'result' => ['value' => 'full'],
        ]),
    ]);

    $this->artisan('tls:show production --context=ctx')
        ->expectsOutputToContain('Full — switch to Full (strict)')
        ->expectsOutputToContain('example.com')
        ->assertExitCode(0);
});

test('on the DNS challenge, tls:show reports 9109 read error when token lacks Zone Settings read', function (): void {
    Process::fake(tlsShowFakes(dnsChallenge: true, proxied: false));
    Saloon::fake([
        ListZonesRequest::class => MockResponse::make([
            'success' => true,
            'result' => [['id' => 'zone-1', 'name' => 'example.com']],
            'result_info' => ['total_pages' => 1],
        ]),
        GetZoneSettingRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 9109]],
            'result' => null,
        ], 403),
    ]);

    $this->artisan('tls:show production --context=ctx')
        ->expectsOutputToContain("can't read SSL mode — the stored token needs Zone → Zone Settings → Read (Cloudflare 9109)")
        ->expectsOutputToContain('example.com')
        ->assertExitCode(0);
});

test('tls:show lists stored certificates that no ingress uses', function (): void {
    Process::fake(tlsShowFakes(dnsChallenge: false, proxied: false, storedCerts: "\"main\": \"app.example.com\"\n\"main\": \"gone.example.com\""));

    $this->artisan('tls:show production --context=ctx')
        ->expectsOutputToContain('gone.example.com')
        ->assertExitCode(0);
});
