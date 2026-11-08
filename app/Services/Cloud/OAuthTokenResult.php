<?php

namespace App\Services\Cloud;

use Illuminate\Support\Carbon;

/** What a provider's token (or refresh) endpoint hands back, normalized across providers. */
final class OAuthTokenResult
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly Carbon $expiresAt,
    ) {}

    /** @param  array<string, mixed>  $body  a decoded token-endpoint JSON response */
    public static function fromResponseBody(array $body): self
    {
        return new self(
            accessToken: (string) $body['access_token'],
            refreshToken: isset($body['refresh_token']) ? (string) $body['refresh_token'] : null,
            expiresAt: now()->addSeconds((int) ($body['expires_in'] ?? 3600)),
        );
    }
}
