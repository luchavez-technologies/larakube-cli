<?php

namespace App\Services\Cloud;

/** What OAuthLoopbackClient::begin() hands back: where to send the browser, and what awaitCode() needs to verify/exchange the result. */
final class PendingOAuthLogin
{
    public function __construct(
        public readonly string $authorizeUrl,
        public readonly string $redirectUri,
        public readonly string $codeVerifier,
        public readonly string $state,
    ) {}
}
