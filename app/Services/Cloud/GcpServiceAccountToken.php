<?php

namespace App\Services\Cloud;

use App\Http\Integrations\Google\GoogleOAuthConnector;
use App\Http\Integrations\Google\Requests\ExchangeServiceAccountJwtRequest;
use RuntimeException;

/**
 * Mints a short-lived Compute token from a service account key, with no gcloud needed. The JWT
 * always goes to Google's own token endpoint, never to a `token_uri` named inside the key file.
 */
final class GcpServiceAccountToken
{
    private const SCOPE = 'https://www.googleapis.com/auth/compute';

    private const AUDIENCE = 'https://oauth2.googleapis.com/token';

    public function mint(string $credentialsPath): string
    {
        $key = json_decode((string) @file_get_contents($credentialsPath), true);

        if (! is_array($key) || ($key['type'] ?? null) !== 'service_account' || empty($key['client_email']) || empty($key['private_key'])) {
            throw new RuntimeException('The Google Cloud credentials file is not a service account key.');
        }

        $privateKey = openssl_pkey_get_private((string) $key['private_key']);
        if ($privateKey === false) {
            throw new RuntimeException('The service account private key could not be read.');
        }

        $now = now()->getTimestamp();
        $unsigned = $this->encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$this->encode([
            'iss' => $key['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::AUDIENCE,
            'iat' => $now,
            'exp' => $now + 600,
        ]);

        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the service account token request.');
        }

        $response = (new GoogleOAuthConnector)->send(new ExchangeServiceAccountJwtRequest($unsigned.'.'.$this->base64Url($signature)));
        $token = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException("Google refused the service account key (HTTP {$response->status()}).");
        }

        return $token;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return $this->base64Url((string) json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
