<?php

use App\Http\Integrations\DigitalOcean\DigitalOceanConnector;
use App\Http\Integrations\DigitalOcean\DigitalOceanOAuthConnector;
use App\Http\Integrations\DigitalOcean\Requests\GetAccountRequest;
use App\Http\Integrations\DigitalOcean\Requests\RefreshTokenRequest;
use App\Http\Integrations\DigitalOcean\Requests\TokenExchangeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('TokenExchangeRequest sends the PKCE code_verifier and never a client_secret', function (): void {
    Saloon::fake([
        TokenExchangeRequest::class => MockResponse::make(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]),
    ]);

    DigitalOceanOAuthConnector::make()->send(
        new TokenExchangeRequest('larakube-cli', 'the-code', 'the-verifier', 'http://127.0.0.1:51204/callback'),
    );

    Saloon::assertSent(function (TokenExchangeRequest $request) {
        $body = $request->body()->all();

        return $body['grant_type'] === 'authorization_code'
            && $body['client_id'] === 'larakube-cli'
            && $body['code'] === 'the-code'
            && $body['code_verifier'] === 'the-verifier'
            && $body['redirect_uri'] === 'http://127.0.0.1:51204/callback'
            && ! array_key_exists('client_secret', $body);
    });
});

test('RefreshTokenRequest sends grant_type=refresh_token with no client_secret', function (): void {
    Saloon::fake([
        RefreshTokenRequest::class => MockResponse::make(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 3600]),
    ]);

    DigitalOceanOAuthConnector::make()->send(new RefreshTokenRequest('larakube-cli', 'the-refresh-token'));

    Saloon::assertSent(function (RefreshTokenRequest $request) {
        $body = $request->body()->all();

        return $body['grant_type'] === 'refresh_token'
            && $body['refresh_token'] === 'the-refresh-token'
            && ! array_key_exists('client_secret', $body);
    });
});

test('GetAccountRequest reads v2/account with the obtained access token', function (): void {
    Saloon::fake([
        GetAccountRequest::class => MockResponse::make(['account' => ['uuid' => 'acct-uuid-1', 'email' => 'a@example.com']]),
    ]);

    $response = DigitalOceanConnector::make('the-access-token')->send(new GetAccountRequest);

    expect($response->json('account.uuid'))->toBe('acct-uuid-1')
        ->and($response->json('account.email'))->toBe('a@example.com');

    Saloon::assertSent(fn ($request, $response) => $request instanceof GetAccountRequest
        && $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer the-access-token');
});
