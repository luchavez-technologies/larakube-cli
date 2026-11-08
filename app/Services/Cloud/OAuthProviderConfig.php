<?php

namespace App\Services\Cloud;

/** The per-provider shape OAuthLoopbackClient needs — no client secret, by design (see Pkce). */
final class OAuthProviderConfig
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public readonly string $authorizeUrl,
        public readonly string $tokenUrl,
        public readonly array $scopes,
        public readonly string $clientId,
    ) {}
}
