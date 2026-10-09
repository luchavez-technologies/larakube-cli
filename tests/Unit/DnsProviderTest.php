<?php

use App\Enums\DnsProvider;

test('DnsProvider cases are Cloudflare and Route53 — GoDaddy deliberately excluded (ExternalDNS Alpha-tier)', function (): void {
    $cases = array_map(fn (DnsProvider $p) => $p->value, DnsProvider::cases());

    expect($cases)->toBe(['cloudflare', 'route53']);
});

test('CLOUDFLARE and ROUTE53 have distinct, correct wiring values', function (): void {
    expect(DnsProvider::CLOUDFLARE->label())->toBe('Cloudflare')
        ->and(DnsProvider::CLOUDFLARE->externalDnsProviderFlag())->toBe('cloudflare')
        ->and(DnsProvider::CLOUDFLARE->legoProviderFlag())->toBe('cloudflare')
        ->and(DnsProvider::CLOUDFLARE->credentialSecretPrefix())->toBe('cloudflare-token-')
        ->and(DnsProvider::CLOUDFLARE->credentialSecretName('example-com'))->toBe('cloudflare-token-example-com')
        ->and(DnsProvider::CLOUDFLARE->credentialSecretKeys())->toBe(['token'])
        ->and(DnsProvider::CLOUDFLARE->traefikAcmeSecretName())->toBe('traefik-acme-cloudflare')
        ->and(DnsProvider::ROUTE53->label())->toBe('AWS Route 53')
        ->and(DnsProvider::ROUTE53->externalDnsProviderFlag())->toBe('aws')
        ->and(DnsProvider::ROUTE53->legoProviderFlag())->toBe('route53')
        ->and(DnsProvider::ROUTE53->credentialSecretPrefix())->toBe('route53-credential-')
        ->and(DnsProvider::ROUTE53->credentialSecretName('example-com'))->toBe('route53-credential-example-com')
        ->and(DnsProvider::ROUTE53->credentialSecretKeys())->toBe(['access_key_id', 'secret_access_key', 'region'])
        ->and(DnsProvider::ROUTE53->traefikAcmeSecretName())->toBe('traefik-acme-route53');
});

test('every provider has non-empty onboarding steps', function (): void {
    foreach (DnsProvider::cases() as $provider) {
        expect($provider->onboardingSteps())->not->toBeEmpty();
    }
});
