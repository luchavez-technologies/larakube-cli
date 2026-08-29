<?php

/**
 * NetBird needs TWO Zitadel applications in one project: the confidential
 * vpn-management client (/oauth2/callback), and a public PKCE client for
 * the dashboard SPA (/peers). This file pins the management app's own set —
 * the dashboard app's URIs are registered separately by
 * SsoWireCommand::ensureNetbirdDashboardApp(), because a browser SPA cannot
 * authenticate as a confidential client and NetBird's identity-provider API
 * requires a client_secret, so one app cannot serve both.
 */

use App\Enums\ClusterTool;

test('the VPN management app registers only its own callback', function (): void {
    $uris = ClusterTool::VPN->oidcRedirectUris('vpn.example.com');

    expect($uris)->toBe(['https://vpn.example.com/oauth2/callback']);
});

test('VPN redirect URIs cover every alias host', function (): void {
    $uris = ClusterTool::VPN->oidcRedirectUris('vpn.example.com', ['vpn.example.org']);

    expect($uris)->toContain('https://vpn.example.org/oauth2/callback');
});

test('VPN post-logout redirect stays on the dashboard logout callback', function (): void {
    expect(ClusterTool::VPN->oidcPostLogoutRedirectUris('vpn.example.com'))
        ->toBe(['https://vpn.example.com/oauth2/logout/callback']);
});

test('the Zitadel app request only asks for JWT when the tool needs it', function (): void {
    $jwt = App\Http\Integrations\Zitadel\Requests\CreateOidcAppRequest::make('p1', 'App', ['https://x/cb'], true, [], true);
    $opaque = App\Http\Integrations\Zitadel\Requests\CreateOidcAppRequest::make('p1', 'App', ['https://x/cb'], true, [], false);

    expect($jwt->body()->all()['accessTokenType'])->toBe('OIDC_TOKEN_TYPE_JWT')
        // Default stays BEARER so no other tool's registration changes.
        ->and($opaque->body()->all()['accessTokenType'])->toBe('OIDC_TOKEN_TYPE_BEARER');
});
