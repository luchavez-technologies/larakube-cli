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
