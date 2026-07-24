<?php

use App\Enums\SharedClusterService;
use App\Traits\ResolvesToolHost;

/**
 * `--domain=` means a BASE domain, but "the domain for this service" is an
 * equally natural reading — and passing the full host silently produced a
 * doubled prefix (secrets.secrets.luchtech.dev) that resolves nowhere.
 */
function domainResolver(): object
{
    return new class
    {
        use ResolvesToolHost;

        public function host(SharedClusterService $service, string $domain): string
        {
            return $this->hostFromDomainOption($service, $domain);
        }
    };
}

test('a base domain gets the service prefix', function () {
    expect(domainResolver()->host(SharedClusterService::SECRETS, 'luchtech.dev'))
        ->toBe('secrets.luchtech.dev');
});

test('a full host is not prefixed a second time', function () {
    // The reported bug: this produced secrets.secrets.luchtech.dev.
    expect(domainResolver()->host(SharedClusterService::SECRETS, 'secrets.luchtech.dev'))
        ->toBe('secrets.luchtech.dev');
});

test('a pasted URL is reduced to a hostname', function () {
    expect(domainResolver()->host(SharedClusterService::SECRETS, 'https://secrets.luchtech.dev/'))
        ->toBe('secrets.luchtech.dev')
        ->and(domainResolver()->host(SharedClusterService::SECRETS, 'HTTPS://LUCHTECH.DEV'))
        ->toBe('secrets.luchtech.dev');
});

test('stray dots and whitespace do not create empty labels', function () {
    expect(domainResolver()->host(SharedClusterService::SECRETS, '  .luchtech.dev.  '))
        ->toBe('secrets.luchtech.dev');
});

test('the doubling guard is per-service, not global', function () {
    // mail.luchtech.dev is a BASE domain as far as the vault service is
    // concerned — only a matching prefix should suppress prefixing.
    expect(domainResolver()->host(SharedClusterService::VAULT, 'mail.luchtech.dev'))
        ->toBe('vault.mail.luchtech.dev')
        ->and(domainResolver()->host(SharedClusterService::VAULT, 'vault.luchtech.dev'))
        ->toBe('vault.luchtech.dev');
});

test('no service doubles its own prefix for any of its two readings', function () {
    foreach (SharedClusterService::cases() as $service) {
        $prefix = $service->hostPrefix();
        if ($prefix === '') {
            continue;
        }

        $fromBase = domainResolver()->host($service, 'example.com');
        $fromHost = domainResolver()->host($service, "{$prefix}.example.com");

        expect($fromBase)->toBe("{$prefix}.example.com")
            ->and($fromHost)->toBe("{$prefix}.example.com")
            ->and($fromHost)->not->toContain("{$prefix}.{$prefix}.");
    }
});
