<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

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

test('re-rendering Traefik keeps the DNS challenge tls:init set', function (): void {
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
