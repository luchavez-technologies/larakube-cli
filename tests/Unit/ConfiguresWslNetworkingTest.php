<?php

/**
 * Tests for ConfiguresWslNetworking::withMirroredNetworking() — the pure
 * string transform that patches ~/.wslconfig so [wsl2] carries
 * networkingMode=mirrored. Kept filesystem-free (the transform is pure); the
 * Process-backed wslConfigPath()/enableMirroredNetworkingInWslConfig() wrappers
 * are thin and exercised on a real machine. See app/Traits/ConfiguresWslNetworking.php.
 */

use App\Traits\ConfiguresWslNetworking;

function wslNetHelper(): object
{
    return new class
    {
        use ConfiguresWslNetworking;

        public function apply(string $ini): string
        {
            return $this->withMirroredNetworking($ini);
        }
    };
}

test('withMirroredNetworking appends a [wsl2] section to an empty config', function (): void {
    expect(wslNetHelper()->apply(''))->toBe("[wsl2]\nnetworkingMode=mirrored\n");
});

test('withMirroredNetworking inserts under an existing [wsl2] header, preserving other keys', function (): void {
    expect(wslNetHelper()->apply("[wsl2]\nmemory=8GB\n"))
        ->toBe("[wsl2]\nnetworkingMode=mirrored\nmemory=8GB\n");
});

test('withMirroredNetworking replaces an existing networkingMode value in place', function (): void {
    expect(wslNetHelper()->apply("[wsl2]\nnetworkingMode=nat\nmemory=8GB\n"))
        ->toBe("[wsl2]\nnetworkingMode=mirrored\nmemory=8GB\n");
});

test('withMirroredNetworking is idempotent', function (): void {
    $once = wslNetHelper()->apply('');
    expect(wslNetHelper()->apply($once))->toBe($once);
});

test('withMirroredNetworking appends [wsl2] after an unrelated existing section', function (): void {
    expect(wslNetHelper()->apply("[experimental]\nautoMemoryReclaim=gradual\n"))
        ->toBe("[experimental]\nautoMemoryReclaim=gradual\n\n[wsl2]\nnetworkingMode=mirrored\n");
});

test('withMirroredNetworking normalises a CRLF (Windows-saved) config when inserting', function (): void {
    expect(wslNetHelper()->apply("[wsl2]\r\nmemory=8GB\r\n"))
        ->toBe("[wsl2]\nnetworkingMode=mirrored\nmemory=8GB\n");
});
