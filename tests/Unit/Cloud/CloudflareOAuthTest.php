<?php

use App\Http\Integrations\Cloudflare\CloudflareConnector;
use App\Http\Integrations\Cloudflare\CloudflareOAuthConnector;
use App\Http\Integrations\Cloudflare\Requests\GetUserRequest;
use App\Http\Integrations\Cloudflare\Requests\RefreshTokenRequest;
use App\Http\Integrations\Cloudflare\Requests\TokenExchangeRequest;
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

    CloudflareOAuthConnector::make()->send(
        new TokenExchangeRequest('larakube-cli', 'the-code', 'the-verifier', 'http://127.0.0.1:51204/callback'),
    );

    Saloon::assertSent(function (TokenExchangeRequest $request) {
        $body = $request->body()->all();

        return $body['grant_type'] === 'authorization_code'
            && $body['code'] === 'the-code'
            && $body['code_verifier'] === 'the-verifier'
            && ! array_key_exists('client_secret', $body);
    });
});

test('RefreshTokenRequest sends grant_type=refresh_token with no client_secret', function (): void {
    Saloon::fake([
        RefreshTokenRequest::class => MockResponse::make(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 3600]),
    ]);

    CloudflareOAuthConnector::make()->send(new RefreshTokenRequest('larakube-cli', 'the-refresh-token'));

    Saloon::assertSent(function (RefreshTokenRequest $request) {
        $body = $request->body()->all();

        return $body['grant_type'] === 'refresh_token'
            && $body['refresh_token'] === 'the-refresh-token'
            && ! array_key_exists('client_secret', $body);
    });
});

test('GetUserRequest reads client/v4/user with the obtained access token', function (): void {
    Saloon::fake([
        GetUserRequest::class => MockResponse::make(['result' => ['id' => 'cf-user-1', 'email' => 'a@example.com'], 'success' => true]),
    ]);

    $response = CloudflareConnector::make('the-access-token')->send(new GetUserRequest);

    expect($response->json('result.id'))->toBe('cf-user-1')
        ->and($response->json('result.email'))->toBe('a@example.com');

    Saloon::assertSent(fn ($request, $response) => $request instanceof GetUserRequest
        && $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer the-access-token');
});
