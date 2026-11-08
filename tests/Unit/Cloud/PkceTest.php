<?php

use App\Services\Cloud\Pkce;

test('verifier is URL-safe and within the RFC 7636 length range', function (): void {
    $verifier = Pkce::verifier();

    expect(strlen($verifier))->toBeGreaterThanOrEqual(43)->toBeLessThanOrEqual(128)
        ->and($verifier)->toMatch('/^[A-Za-z0-9_-]+$/');
});

test('two verifiers are never the same', function (): void {
    expect(Pkce::verifier())->not->toBe(Pkce::verifier());
});

test('challenge matches the RFC 7636 appendix B test vector', function (): void {
    // https://www.rfc-editor.org/rfc/rfc7636#appendix-B
    $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    expect(Pkce::challenge($verifier))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});
