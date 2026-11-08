<?php

namespace App\Services\Cloud;

use App\Exceptions\OAuthStateMismatchException;
use Illuminate\Support\Str;

/**
 * The provider-agnostic half of a "Sign in with X" OAuth flow for a native
 * app: Authorization Code + PKCE, no client secret, redirecting back to a
 * short-lived local HTTP listener instead of a backend LaraKube doesn't run.
 * Token exchange itself is deliberately NOT here — that's a per-provider
 * Saloon request (different base URL, different response shape), built by
 * the caller once it has the authorization code from awaitCode().
 *
 * Mirrors the separation VpnSsoLoginCommand already uses for its own device-
 * code grant: this class does the OAuth mechanics only, the calling command
 * does all the printing/spinners/browser-opening around it.
 */
final class OAuthLoopbackClient
{
    public function __construct(private readonly LoopbackHttpServerInterface $server) {}

    /** Starts the local listener and builds the URL to send the browser to. */
    public function begin(OAuthProviderConfig $config): PendingOAuthLogin
    {
        $port = $this->server->listen();
        $redirectUri = "http://127.0.0.1:{$port}/callback";
        $verifier = Pkce::verifier();
        $state = Str::random(32);

        $authorizeUrl = $config->authorizeUrl.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $config->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $config->scopes),
            'state' => $state,
            'code_challenge' => Pkce::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);

        return new PendingOAuthLogin($authorizeUrl, $redirectUri, $verifier, $state);
    }

    /**
     * Blocks until the browser redirect lands, or $timeoutSeconds elapses.
     *
     * @throws OAuthStateMismatchException if a callback arrives but its
     *                                     `state` doesn't match $pending's —
     *                                     discarded, never exchanged.
     */
    public function awaitCode(PendingOAuthLogin $pending, int $timeoutSeconds = 120): ?string
    {
        $result = $this->server->awaitCallback($timeoutSeconds);

        if ($result === null) {
            return null;
        }

        if (! hash_equals($pending->state, (string) ($result['state'] ?? ''))) {
            throw new OAuthStateMismatchException(
                'The browser redirect did not carry the expected state — discarding it.',
            );
        }

        return $result['code'] ?? null;
    }

    public function close(): void
    {
        $this->server->close();
    }
}
