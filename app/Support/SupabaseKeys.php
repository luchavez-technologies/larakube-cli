<?php

namespace App\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Generates self-hosted Supabase secrets and HS256 JWT API keys matching
 * upstream `docker/utils/generate-keys.sh`, plus optional `sb_publishable_` /
 * `sb_secret_` tokens.
 */
final class SupabaseKeys
{
    /** @return array<string, string> */
    public static function generate(?int $issuedAt = null): array
    {
        $iat = $issuedAt ?? time();
        $jwtSecret = base64_encode(random_bytes(30));

        return [
            'jwt-secret' => $jwtSecret,
            ...self::apiKeys($jwtSecret, $iat),
            'secret-key-base' => base64_encode(random_bytes(48)),
            'realtime-db-enc-key' => bin2hex(random_bytes(8)),
            'realtime-release-cookie' => bin2hex(random_bytes(28)),
            'vault-enc-key' => bin2hex(random_bytes(16)),
            'pg-meta-crypto-key' => base64_encode(random_bytes(24)),
            'db-password' => bin2hex(random_bytes(16)),
            'dashboard-username' => 'supabase',
            'dashboard-password' => bin2hex(random_bytes(16)),
            'publishable-key' => self::opaqueKey('sb_publishable_'),
            'secret-key' => self::opaqueKey('sb_secret_'),
            // Storage's S3-compatible endpoint (/storage/v1/s3), in upstream's .env.example shape.
            's3-protocol-access-key-id' => bin2hex(random_bytes(16)),
            's3-protocol-access-key-secret' => bin2hex(random_bytes(32)),
        ];
    }

    /**
     * The anon and service_role API keys: HS256 JWTs signed with $jwtSecret, valid for five years.
     *
     * @return array{anon-key: string, service-role-key: string}
     */
    public static function apiKeys(string $jwtSecret, ?int $issuedAt = null): array
    {
        $iat = $issuedAt ?? time();
        $exp = $iat + 5 * 3600 * 24 * 365;

        return [
            'anon-key' => self::signHs256(['role' => 'anon', 'iss' => 'supabase', 'iat' => $iat, 'exp' => $exp], $jwtSecret),
            'service-role-key' => self::signHs256(['role' => 'service_role', 'iss' => 'supabase', 'iat' => $iat, 'exp' => $exp], $jwtSecret),
        ];
    }

    /** @param  array<string, mixed>  $payload */
    public static function signHs256(array $payload, string $secret): string
    {
        $header = self::base64UrlEncode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $body = self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = "{$header}.{$body}";
        $signature = self::base64UrlEncode(hash_hmac('sha256', $signingInput, $secret, true));

        return "{$signingInput}.{$signature}";
    }

    /**
     * What the opaque sb_ keys need to work, as upstream's `docker/utils/add-new-auth-keys.sh` makes it:
     * an EC P-256 pair (Auth signs with `jwt-keys`; the rest verify with the public `jwt-jwks`), both also
     * carrying the HS256 $jwtSecret so tokens signed before keep working, and the ES256 anon and
     * service_role tokens the gateway swaps the sb_ keys for. All four share one key id, so they are made
     * and replaced together.
     *
     * @return array{jwt-keys: string, jwt-jwks: string, anon-key-asymmetric: string, service-role-key-asymmetric: string}
     */
    public static function asymmetricKeys(string $jwtSecret, ?int $issuedAt = null): array
    {
        // private_key_bits means nothing for an EC key, but the bundled static PHP has no openssl.cnf to
        // default it from and refuses any key "shorter than 384 bits" without it, EC included.
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048]);
        if ($key === false) {
            throw new RuntimeException('Could not generate an EC P-256 key pair.');
        }

        $ec = (array) (openssl_pkey_get_details($key)['ec'] ?? []);
        $kid = (string) \Illuminate\Support\Str::uuid();
        $public = [
            'kty' => 'EC', 'kid' => $kid, 'use' => 'sig', 'key_ops' => ['verify'], 'alg' => 'ES256', 'ext' => true,
            'crv' => 'P-256', 'x' => self::base64UrlEncode((string) $ec['x']), 'y' => self::base64UrlEncode((string) $ec['y']),
        ];
        $private = [...$public, 'key_ops' => ['sign', 'verify'], 'd' => self::base64UrlEncode((string) $ec['d'])];
        $symmetric = ['kty' => 'oct', 'k' => self::base64UrlEncode($jwtSecret), 'alg' => 'HS256'];

        $iat = $issuedAt ?? time();
        $exp = $iat + 5 * 3600 * 24 * 365;

        return [
            'jwt-keys' => (string) json_encode([$private, $symmetric], JSON_UNESCAPED_SLASHES),
            'jwt-jwks' => (string) json_encode(['keys' => [$public, $symmetric]], JSON_UNESCAPED_SLASHES),
            'anon-key-asymmetric' => self::signEs256(['role' => 'anon', 'iss' => 'supabase', 'iat' => $iat, 'exp' => $exp], $key, $kid),
            'service-role-key-asymmetric' => self::signEs256(['role' => 'service_role', 'iss' => 'supabase', 'iat' => $iat, 'exp' => $exp], $key, $kid),
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private static function signEs256(array $payload, OpenSSLAsymmetricKey $key, string $kid): string
    {
        $header = self::base64UrlEncode((string) json_encode(['alg' => 'ES256', 'typ' => 'JWT', 'kid' => $kid], JSON_UNESCAPED_SLASHES));
        $body = self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = "{$header}.{$body}";

        if (! openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign an ES256 token.');
        }

        return "{$signingInput}.".self::base64UrlEncode(self::derToRawSignature((string) $der));
    }

    /**
     * openssl_sign() gives ECDSA signatures as DER (SEQUENCE of two INTEGERs); JWS wants r and s as
     * two fixed 32-byte halves.
     */
    private static function derToRawSignature(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $parts = '';

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $integer = ltrim(substr($der, $offset + 2, $length), "\x00");
            $parts .= str_pad($integer, 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts;
    }

    /** An opaque API key in upstream's shape: prefix, 22 random characters, `_`, an 8-character checksum. */
    private static function opaqueKey(string $prefix): string
    {
        $intermediate = $prefix.substr(self::base64UrlEncode(random_bytes(17)), 0, 22);
        $checksum = substr(self::base64UrlEncode(hash('sha256', 'supabase-self-hosted|'.$intermediate, true)), 0, 8);

        return "{$intermediate}_{$checksum}";
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
