<?php

use App\Http\Integrations\Cloudflare\Requests\GetIpRangesRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * `traefik:setup {env}` re-renders a cloud cluster's Traefik. It must keep
 * whatever `tls:init` chose and the account the cluster already uses.
 */
function traefikSetupCloudFakes(?string &$manifest, bool $dnsChallenge): array
{
    return [
        '*get secret traefik-acme-cloudflare -n traefik -o name*' => Process::result(output: $dnsChallenge ? 'secret/traefik-acme-cloudflare' : ''),
        '*get deployment traefik -n traefik -o jsonpath*' => Process::result(
            output: '["--certificatesresolvers.letsencrypt.acme.email=cluster@example.com","--providers.kubernetesingress.ingressendpoint.ip=203.0.113.10"]',
        ),
        '*apply -f *traefik-cloud.yaml*' => function (PendingProcess $process) use (&$manifest) {
            preg_match("/apply -f '([^']+)'/", $process->command, $m);
            $manifest = file_get_contents($m[1]);

            return Process::result(output: 'applied');
        },
        '*' => Process::result(output: ''),
    ];
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('re-rendering Traefik keeps the DNS challenge tls:init set', function (): void {
    Saloon::fake([GetIpRangesRequest::class => MockResponse::make(['success' => true, 'result' => ['ipv4_cidrs' => ['173.245.48.0/20'], 'ipv6_cidrs' => ['2400:cb00::/32']]])]);
    $manifest = null;
    Process::fake(traefikSetupCloudFakes($manifest, dnsChallenge: true));

    $this->artisan('traefik:setup production --context=ctx')->assertExitCode(0);

    expect($manifest)
        ->toContain('dnschallenge.provider=cloudflare')
        ->toContain('CF_DNS_API_TOKEN')
        ->not->toContain('httpchallenge');
});

test('re-rendering Traefik keeps the HTTP challenge on clusters that never ran tls:init', function (): void {
    $manifest = null;
    Process::fake(traefikSetupCloudFakes($manifest, dnsChallenge: false));

    $this->artisan('traefik:setup production --context=ctx')->assertExitCode(0);

    expect($manifest)->toContain('httpchallenge.entrypoint=web')->not->toContain('dnschallenge');
});

test('re-rendering Traefik keeps the cluster\'s own Let\'s Encrypt email over this machine\'s', function (): void {
    $manifest = null;
    Process::fake(traefikSetupCloudFakes($manifest, dnsChallenge: false));

    $this->artisan('traefik:setup production --context=ctx')->assertExitCode(0);

    expect($manifest)->toContain('acme.email=cluster@example.com');
});

test('a Cloudflare cluster trusts Cloudflare\'s edge for forwarded headers, so apps see the visitor', function (): void {
    Saloon::fake([GetIpRangesRequest::class => MockResponse::make(['success' => true, 'result' => ['ipv4_cidrs' => ['173.245.48.0/20', '103.21.244.0/22'], 'ipv6_cidrs' => ['2400:cb00::/32']]])]);
    $manifest = null;
    Process::fake(traefikSetupCloudFakes($manifest, dnsChallenge: true));

    $this->artisan('traefik:setup production --context=ctx')->assertExitCode(0);

    expect($manifest)
        ->toContain('--entrypoints.websecure.forwardedHeaders.trustedIPs=173.245.48.0/20,103.21.244.0/22,2400:cb00::/32')
        ->toContain('--entrypoints.web.forwardedHeaders.trustedIPs=173.245.48.0/20,103.21.244.0/22,2400:cb00::/32');
});

test('if Cloudflare\'s range list can\'t be fetched, the ranges the running Traefik trusts are kept', function (): void {
    Saloon::fake([GetIpRangesRequest::class => MockResponse::make(['success' => false], 500)]);
    $manifest = null;
    // Same key as the helper's: listed after it, this value replaces its one.
    Process::fake([
        ...traefikSetupCloudFakes($manifest, dnsChallenge: true),
        '*get deployment traefik -n traefik -o jsonpath*' => Process::result(
            output: '["--certificatesresolvers.letsencrypt.acme.email=cluster@example.com","--providers.kubernetesingress.ingressendpoint.ip=203.0.113.10","--entrypoints.websecure.forwardedHeaders.trustedIPs=198.41.128.0/17"]',
        ),
    ]);

    $this->artisan('traefik:setup production --context=ctx')->assertExitCode(0);

    expect($manifest)->toContain('--entrypoints.websecure.forwardedHeaders.trustedIPs=198.41.128.0/17');
});

test('an HTTP-challenge cluster trusts no forwarded headers', function (): void {
    $manifest = null;
    Process::fake(traefikSetupCloudFakes($manifest, dnsChallenge: false));

    $this->artisan('traefik:setup production --context=ctx')->assertExitCode(0);

    expect($manifest)->not->toContain('forwardedHeaders');
});

test('the VPN allow-list matches the real connection address, never a forwarded header', function (): void {
    // With Cloudflare trusted for X-Forwarded-For, an ipStrategy here would let
    // anyone claim a VPN address in a header.
    $middleware = view('k8s.vpn.ip-allow-list-middleware', ['name' => 'x-vpn-only', 'namespace' => 'larakube-shared'])->render();

    expect($middleware)->toContain('ipAllowList')->not->toContain('ipStrategy');
});
