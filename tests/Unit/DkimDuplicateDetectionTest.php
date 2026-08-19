<?php

use App\Traits\InteractsWithStalwartApi;

/**
 * Duplicate-DKIM detection, tested on the data rather than through a command.
 *
 * The distinction that matters here is stage. Stalwart rotates signing keys on a
 * schedule (every signature carries a nextTransitionAt), so a domain holding two
 * keys is NORMAL mid-rotation — only two *active* keys get published and signed
 * with, and only that produces the duplicate DKIM-Signature header that SES
 * rejects with 554. A detector that just counted keys per domain would fire a
 * false alarm every rotation.
 */
function dkimHarness(): object
{
    return new class
    {
        use InteractsWithStalwartApi;

        /** @param  list<array{domain: string, stage: string}>  $signatures */
        public function duplicates(array $signatures): array
        {
            return $this->stalwartDuplicateActiveDkim($signatures);
        }

        public function ed25519Types(): array
        {
            return self::DKIM_ED25519_TYPES;
        }
    };
}

function sig(string $domain, string $stage = 'active'): array
{
    return ['domain' => $domain, 'stage' => $stage];
}

test('a single active key per domain is not a duplicate', function (): void {
    expect(dkimHarness()->duplicates([
        sig('luchtech.dev'),
        sig('example.com'),
    ]))->toBe([]);
});

test('two active keys on one domain is the 554 bounce state', function (): void {
    expect(dkimHarness()->duplicates([
        sig('luchtech.dev'),
        sig('luchtech.dev'),
        sig('example.com'),
    ]))->toBe(['luchtech.dev' => 2]);
});

test('a key mid-rotation does not count as a duplicate', function (): void {
    // The exact false positive this guards: Stalwart stages the next key well
    // before promoting it, so active + pending is the healthy steady state for
    // roughly a quarter of the year.
    expect(dkimHarness()->duplicates([
        sig('luchtech.dev', 'active'),
        sig('luchtech.dev', 'pending'),
    ]))->toBe([]);

    expect(dkimHarness()->duplicates([
        sig('luchtech.dev', 'active'),
        sig('luchtech.dev', 'retiring'),
        sig('luchtech.dev', 'retired'),
    ]))->toBe([]);
});

test('both DKIM generations of Ed25519 are pruned, not just DKIM1', function (): void {
    // x:DkimSignature has four variants; an earlier prune matched only
    // Dkim1Ed25519Sha256, so a Dkim2 Ed25519 key survived and re-created the
    // duplicate header.
    expect(dkimHarness()->ed25519Types())
        ->toContain('Dkim1Ed25519Sha256')
        ->toContain('Dkim2Ed25519Sha256');
});
