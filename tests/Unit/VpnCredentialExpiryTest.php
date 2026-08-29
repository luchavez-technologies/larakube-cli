<?php

/**
 * The PAT and setup key vpn:init mints expire within milliseconds of each
 * other, and an expired PAT cannot mint its own replacement — so the countdown
 * vpn:show prints is the only thing that turns vpn:rotate into a safety net
 * rather than something you have to remember unprompted.
 */

use App\Traits\InteractsWithVpn;

function vpnExpiryHelper(): object
{
    return new class
    {
        use InteractsWithVpn;

        public function days(?string $timestamp): ?int
        {
            return $this->vpnDaysUntil($timestamp);
        }
    };
}

test('vpnDaysUntil counts whole days ahead', function (): void {
    expect(vpnExpiryHelper()->days(now()->addDays(30)->toIso8601String()))->toBe(30)
        ->and(vpnExpiryHelper()->days(now()->addDays(365)->toIso8601String()))->toBe(365);
});

test('vpnDaysUntil goes negative once expired, rather than clamping to zero', function (): void {
    // A clamped 0 reads as "expires today" and hides that everything is
    // already broken.
    expect(vpnExpiryHelper()->days(now()->subDays(5)->toIso8601String()))->toBeLessThan(0);
});

test('vpnDaysUntil is null for absent or unparseable timestamps', function (): void {
    expect(vpnExpiryHelper()->days(null))->toBeNull()
        ->and(vpnExpiryHelper()->days(''))->toBeNull()
        ->and(vpnExpiryHelper()->days('not-a-date'))->toBeNull();
});
