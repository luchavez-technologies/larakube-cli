<?php

use App\Data\InstanceData;
use App\Enums\ClusterTool;
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

test('a base domain gets the service prefix', function (): void {
    expect(domainResolver()->host(SharedClusterService::SECRETS, 'luchtech.dev'))
        ->toBe('secrets.luchtech.dev');
});

test('a full host is not prefixed a second time', function (): void {
    // The reported bug: this produced secrets.secrets.luchtech.dev.
    expect(domainResolver()->host(SharedClusterService::SECRETS, 'secrets.luchtech.dev'))
        ->toBe('secrets.luchtech.dev');
});

test('a pasted URL is reduced to a hostname', function (): void {
    expect(domainResolver()->host(SharedClusterService::SECRETS, 'https://secrets.luchtech.dev/'))
        ->toBe('secrets.luchtech.dev')
        ->and(domainResolver()->host(SharedClusterService::SECRETS, 'HTTPS://LUCHTECH.DEV'))
        ->toBe('secrets.luchtech.dev');
});

test('stray dots and whitespace do not create empty labels', function (): void {
    expect(domainResolver()->host(SharedClusterService::SECRETS, '  .luchtech.dev.  '))
        ->toBe('secrets.luchtech.dev');
});

test('the doubling guard is per-service, not global', function (): void {
    // mail.luchtech.dev is a BASE domain as far as the vault service is
    // concerned — only a matching prefix should suppress prefixing.
    expect(domainResolver()->host(SharedClusterService::VAULT, 'mail.luchtech.dev'))
        ->toBe('vault.mail.luchtech.dev')
        ->and(domainResolver()->host(SharedClusterService::VAULT, 'vault.luchtech.dev'))
        ->toBe('vault.luchtech.dev');
});

test('no service doubles its own prefix for any of its two readings', function (): void {
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

/** A resolver whose registry reports $registeredHost for every tool. */
function domainResolverRegisteredAt(?string $registeredHost): object
{
    return new class($registeredHost)
    {
        use ResolvesToolHost;

        public function __construct(private ?string $registeredHost) {}

        /** @return list<InstanceData> */
        public function getAllToolInstanceData(string $kubectl, ClusterTool $tool): array
        {
            return $this->registeredHost === null ? [] : [new InstanceData(host: $this->registeredHost)];
        }

        public function host(SharedClusterService $service, ClusterTool $tool, string $domain): string
        {
            return $this->hostFromDomainOption($service, $domain, '', $tool, 'kubectl');
        }
    };
}

test('a host the tool already serves is used as-is, whatever its prefix looks like', function (): void {
    // monitor's prefix is `grafana`, so its own registered host does not start
    // with it — prefixing turned `monitor:init --domain=monitor.luchtech.dev`
    // into grafana.monitor.luchtech.dev and built a second, parallel instance
    // with its own Deployments, Ingress and Commons database.
    expect(domainResolverRegisteredAt('monitor.luchtech.dev')
        ->host(SharedClusterService::GRAFANA, ClusterTool::MONITOR, 'monitor.luchtech.dev'))
        ->toBe('monitor.luchtech.dev');
});

test('an unregistered domain is still treated as a base domain', function (): void {
    // The registry check must not swallow the normal reading.
    expect(domainResolverRegisteredAt('monitor.luchtech.dev')
        ->host(SharedClusterService::GRAFANA, ClusterTool::MONITOR, 'example.com'))
        ->toBe('grafana.example.com')
        ->and(domainResolverRegisteredAt(null)
            ->host(SharedClusterService::GRAFANA, ClusterTool::MONITOR, 'luchtech.dev'))
        ->toBe('grafana.luchtech.dev');
});
