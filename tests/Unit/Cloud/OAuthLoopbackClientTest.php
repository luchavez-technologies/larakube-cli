<?php

use App\Exceptions\OAuthStateMismatchException;
use App\Services\Cloud\FakeLoopbackHttpServer;
use App\Services\Cloud\OAuthLoopbackClient;
use App\Services\Cloud\OAuthProviderConfig;

function oauthLoopbackConfig(): OAuthProviderConfig
{
    return new OAuthProviderConfig(
        authorizeUrl: 'https://cloud.digitalocean.com/v1/oauth/authorize',
        tokenUrl: 'https://cloud.digitalocean.com/v1/oauth/token',
        scopes: ['read', 'write'],
        clientId: 'larakube-cli',
    );
}

test('begin builds a PKCE authorize URL with no client secret anywhere in it', function (): void {
    $client = new OAuthLoopbackClient(new FakeLoopbackHttpServer);

    $pending = $client->begin(oauthLoopbackConfig());

    expect($pending->authorizeUrl)->toStartWith('https://cloud.digitalocean.com/v1/oauth/authorize?')
        ->and($pending->authorizeUrl)->toContain('response_type=code')
        ->and($pending->authorizeUrl)->toContain('client_id=larakube-cli')
        ->and($pending->authorizeUrl)->toContain('code_challenge_method=S256')
        ->and($pending->authorizeUrl)->toContain(rawurlencode($pending->redirectUri))
        ->and($pending->authorizeUrl)->not->toContain('secret')
        ->and($pending->redirectUri)->toBe('http://127.0.0.1:51204/callback')
        ->and(strlen($pending->codeVerifier))->toBeGreaterThanOrEqual(43)->toBeLessThanOrEqual(128)
        ->and($pending->state)->toHaveLength(32);
});

test('awaitCode returns the code once the callback state matches', function (): void {
    $server = new FakeLoopbackHttpServer;
    $client = new OAuthLoopbackClient($server);
    $pending = $client->begin(oauthLoopbackConfig());

    $server->queueResponse(['code' => 'the-auth-code', 'state' => $pending->state]);

    expect($client->awaitCode($pending))->toBe('the-auth-code');
});

test('awaitCode returns null on a timeout (no callback arrived)', function (): void {
    $server = new FakeLoopbackHttpServer;
    $client = new OAuthLoopbackClient($server);
    $pending = $client->begin(oauthLoopbackConfig());

    $server->queueResponse(null);

    expect($client->awaitCode($pending))->toBeNull();
});

test('awaitCode discards a callback whose state does not match, rather than exchanging it', function (): void {
    $server = new FakeLoopbackHttpServer;
    $client = new OAuthLoopbackClient($server);
    $pending = $client->begin(oauthLoopbackConfig());

    $server->queueResponse(['code' => 'attacker-supplied-code', 'state' => 'forged-state']);

    $client->awaitCode($pending);
})->throws(OAuthStateMismatchException::class);
