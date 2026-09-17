<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function tlsRemoveFakes(array &$captured, bool $proxied = false, bool $dnsChallenge = true): array
{
    $captured = ['manifest' => null];

    $annotations = ['traefik.ingress.kubernetes.io/router.tls.certresolver' => 'letsencrypt'];
    if ($proxied) {
        $annotations['external-dns.alpha.kubernetes.io/cloudflare-proxied'] = 'true';
    }

    return [
        '*get secret traefik-acme-cloudflare -n traefik -o name*' => Process::result(output: $dnsChallenge ? 'secret/traefik-acme-cloudflare' : ''),
        '*get ingress -A -o json*' => Process::result(output: (string) json_encode(['items' => [[
            'metadata' => ['namespace' => 'apps', 'name' => 'web', 'annotations' => $annotations],
            'spec' => ['rules' => [['host' => 'app.example.com']]],
        ]]])),
        '*get deployment traefik -n traefik -o jsonpath*' => Process::result(
            output: '["--certificatesresolvers.letsencrypt.acme.email=ops@example.com","--providers.kubernetesingress.ingressendpoint.ip=203.0.113.10"]',
        ),
        '*apply -f *traefik-cloud.yaml*' => function (PendingProcess $process) use (&$captured) {
            preg_match("/apply -f '([^']+)'/", $process->command, $m);
            $captured['manifest'] = file_get_contents($m[1]);

            return Process::result(output: 'applied');
        },
        '*' => Process::result(output: ''),
    ];
}

test('tls:remove re-renders Traefik with the HTTP challenge before deleting the token Secret', function (): void {
    $captured = [];
    Process::fake(tlsRemoveFakes($captured));

    $this->artisan('tls:remove production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('back on the HTTP challenge')
        ->assertExitCode(0);

    expect($captured['manifest'])
        ->toContain('httpchallenge.entrypoint=web')
        ->not->toContain('CF_DNS_API_TOKEN');

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'delete secret traefik-acme-cloudflare -n traefik'));
    // dns:init's own tokens are never touched.
    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'delete') && str_contains($process->command, 'cloudflare-token-'));
});

test('tls:remove refuses while a host is proxied, naming it', function (): void {
    $captured = [];
    Process::fake(tlsRemoveFakes($captured, proxied: true));

    $this->artisan('tls:remove production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('app.example.com')
        ->assertExitCode(1);

    expect($captured['manifest'])->toBeNull();
    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'delete secret'));
});

test('tls:remove does nothing when the cluster already uses the HTTP challenge', function (): void {
    $captured = [];
    Process::fake(tlsRemoveFakes($captured, dnsChallenge: false));

    $this->artisan('tls:remove production --context=ctx --force --no-interaction')
        ->expectsOutputToContain('already uses the HTTP challenge')
        ->assertExitCode(0);

    expect($captured['manifest'])->toBeNull();
});
