<?php

namespace App\Services\Cloud;

/**
 * RFC 7636 Proof Key for Code Exchange — lets a native app (this CLI) prove it
 * is the same client that started the authorize request, without ever holding
 * a client secret. The verifier never leaves this process until the final
 * token-exchange call; only its SHA-256 challenge goes into the authorize URL.
 */
final class Pkce
{
    /** A high-entropy, URL-safe verifier within RFC 7636's 43-128 character range. */
    public static function verifier(): string
    {
        return self::base64UrlEncode(random_bytes(40));
    }

    /** S256 challenge for a given verifier. */
    public static function challenge(string $verifier): string
    {
        return self::base64UrlEncode(hash('sha256', $verifier, true));
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
