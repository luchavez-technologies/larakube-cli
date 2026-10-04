<?php

use App\Traits\InteractsWithTrust;

function dnsmasqHarness(): object
{
    return new class
    {
        use InteractsWithTrust;

        public function tlds(string $conf): array
        {
            return $this->parseDnsmasqTlds($conf);
        }

        public function conf(array $tlds): string
        {
            return $this->buildDnsmasqConf($tlds);
        }

        public function resolved(array $tlds): string
        {
            return $this->buildResolvedDropIn($tlds);
        }

        public function resolvedTlds(string $content): array
        {
            return $this->parseResolvedDropInTlds($content);
        }
    };
}

test('parseDnsmasqTlds extracts every wildcarded TLD from existing conf content', function (): void {
    $conf = "listen-address=127.0.0.1\nbind-interfaces\naddress=/.kube/127.0.0.1\naddress=/.test/127.0.0.1\n";

    expect(dnsmasqHarness()->tlds($conf))->toBe(['kube', 'test']);
});

test('parseDnsmasqTlds returns an empty array for a fresh/empty conf', function (): void {
    expect(dnsmasqHarness()->tlds(''))->toBe([]);
});

test('buildDnsmasqConf wildcards every given TLD and dedupes', function (): void {
    $conf = dnsmasqHarness()->conf(['kube', 'test', 'kube']);

    expect($conf)->toContain('address=/.kube/127.0.0.1')
        ->and($conf)->toContain('address=/.test/127.0.0.1')
        ->and(substr_count($conf, 'address=/.kube/127.0.0.1'))->toBe(1);
});

test('parsing the output of buildDnsmasqConf round-trips back to the same TLD set', function (): void {
    $harness = dnsmasqHarness();
    $tlds = ['kube', 'test', 'localhost'];

    expect($harness->tlds($harness->conf($tlds)))->toBe($tlds);
});

test('merging a new TLD into existing conf content keeps prior TLDs covered', function (): void {
    $harness = dnsmasqHarness();
    $existing = $harness->conf(['kube']);

    $merged = array_unique(array_merge($harness->tlds($existing), ['test']));

    expect($merged)->toBe(['kube', 'test']);
});

test('the systemd-resolved drop-in routes only the local TLDs to dnsmasq on 127.0.0.1', function (): void {
    $content = dnsmasqHarness()->resolved(['kube', 'test', 'kube']);

    expect($content)->toBe("[Resolve]\nDNS=127.0.0.1\nDomains=~kube ~test\n");
});

test('the TLDs of an existing drop-in are read back, so adding one keeps the others', function (): void {
    $harness = dnsmasqHarness();

    expect($harness->resolvedTlds($harness->resolved(['kube', 'test'])))->toBe(['kube', 'test'])
        ->and($harness->resolvedTlds(''))->toBe([])
        ->and($harness->resolvedTlds("[Resolve]\nDNS=1.1.1.1\n"))->toBe([]);
});
