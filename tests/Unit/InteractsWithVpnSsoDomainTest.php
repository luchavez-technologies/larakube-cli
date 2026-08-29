<?php

/**
 * Tests for InteractsWithVpn::vpnSsoDomain() — the value that lands in
 * NETBIRD_MGMT_SINGLE_ACCOUNT_MODE_DOMAIN. Getting this wrong silently splits
 * every SSO login into its own NetBird account (one /16 each, no route between
 * them), so the derivation and the override both need pinning.
 */

use App\Traits\InteractsWithVpn;

function vpnSsoDomainHelper(): object
{
    return new class
    {
        use InteractsWithVpn;

        public function ssoDomain(string $host, ?string $override = null): string
        {
            return $this->vpnSsoDomain($host, $override);
        }
    };
}

test('vpnSsoDomain strips the vpn prefix back off the resolved host', function (): void {
    expect(vpnSsoDomainHelper()->ssoDomain('vpn.example.com'))->toBe('example.com');
});

test('vpnSsoDomain keeps every remaining label of a deeper host', function (): void {
    expect(vpnSsoDomainHelper()->ssoDomain('vpn.eu.example.com'))->toBe('eu.example.com');
});

test('vpnSsoDomain leaves a host that carries no vpn prefix untouched', function (): void {
    // resolveToolHost() can hand back a host the operator typed verbatim; only
    // the service's own prefix is ever stripped, never an arbitrary first label.
    expect(vpnSsoDomainHelper()->ssoDomain('example.com'))->toBe('example.com');
});

test('vpnSsoDomain does not strip a label that merely starts with vpn', function (): void {
    expect(vpnSsoDomainHelper()->ssoDomain('vpnx.example.com'))->toBe('vpnx.example.com');
});

test('vpnSsoDomain prefers an explicit override over the derived domain', function (): void {
    // The SSO users' email domain is free to differ from the cluster's base
    // domain — that they coincide on the dogfooding cluster is a coincidence.
    expect(vpnSsoDomainHelper()->ssoDomain('vpn.cluster.example', 'staff.example.org'))
        ->toBe('staff.example.org');
});

test('vpnSsoDomain normalises an override pasted as an email domain', function (): void {
    expect(vpnSsoDomainHelper()->ssoDomain('vpn.example.com', '@Staff.Example.Org'))
        ->toBe('staff.example.org');
});

test('vpnSsoDomain falls back to derivation when the override is blank or whitespace', function (): void {
    expect(vpnSsoDomainHelper()->ssoDomain('vpn.example.com', ''))->toBe('example.com')
        ->and(vpnSsoDomainHelper()->ssoDomain('vpn.example.com', '   '))->toBe('example.com')
        ->and(vpnSsoDomainHelper()->ssoDomain('vpn.example.com', null))->toBe('example.com');
});
