<?php

namespace App\Support;

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
            'vault-enc-key' => bin2hex(random_bytes(16)),
            'pg-meta-crypto-key' => base64_encode(random_bytes(24)),
            'db-password' => bin2hex(random_bytes(16)),
            'dashboard-username' => 'supabase',
            'dashboard-password' => bin2hex(random_bytes(16)),
            'publishable-key' => 'sb_publishable_'.self::base64UrlEncode(random_bytes(18)),
            'secret-key' => 'sb_secret_'.self::base64UrlEncode(random_bytes(24)),
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

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
