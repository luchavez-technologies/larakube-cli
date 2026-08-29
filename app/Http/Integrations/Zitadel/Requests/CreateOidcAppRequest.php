<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class CreateOidcAppRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    /**
     * @param  list<string>  $redirectUris
     * @param  list<string>  $postLogoutRedirectUris
     */
    public function __construct(
        protected readonly string $projectId,
        protected readonly string $name,
        protected readonly array $redirectUris,
        protected readonly bool $publicClient,
        protected readonly array $postLogoutRedirectUris,
        protected readonly bool $jwtAccessToken = false,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "management/v1/projects/{$this->projectId}/apps/oidc";
    }

    protected function defaultBody(): array
    {
        $body = [
            'name' => $this->name,
            'redirectUris' => $this->redirectUris,
            'responseTypes' => ['OIDC_RESPONSE_TYPE_CODE'],
            'grantTypes' => ['OIDC_GRANT_TYPE_AUTHORIZATION_CODE', 'OIDC_GRANT_TYPE_REFRESH_TOKEN'],
            'appType' => 'OIDC_APP_TYPE_WEB',
            'authMethodType' => $this->publicClient ? 'OIDC_AUTH_METHOD_TYPE_NONE' : 'OIDC_AUTH_METHOD_TYPE_BASIC',
            // Zitadel's default access token is an OPAQUE bearer string. Any
            // client that must READ the token — decode its claims, or verify it
            // itself against the issuer's JWKS rather than calling the
            // introspection endpoint — needs a JWT instead. Confirmed live
            // 2026-08-28: NetBird's dashboard exchanged its code for a 200 OK
            // opaque token, could not parse it, and silently re-ran the whole
            // login in a loop, never once calling the management API.
            'accessTokenType' => $this->jwtAccessToken ? 'OIDC_TOKEN_TYPE_JWT' : 'OIDC_TOKEN_TYPE_BEARER',
            // Assert userinfo (email, name, …) INTO the ID token. Zitadel
            // otherwise serves those claims only from the userinfo endpoint,
            // but ID-token-reading clients (Documenso/NextAuth) then fail
            // with "Missing email". Harmless for userinfo-reading clients.
            'idTokenUserinfoAssertion' => true,
        ];

        if ($this->postLogoutRedirectUris !== []) {
            // SPAs that use RP-initiated logout (oCIS web) send their own
            // origin root to end_session; Zitadel 400s it
            // ("post_logout_redirect_uri invalid") unless pre-registered
            // here — the live logout bug.
            $body['postLogoutRedirectUris'] = $this->postLogoutRedirectUris;
        }

        return $body;
    }
}
