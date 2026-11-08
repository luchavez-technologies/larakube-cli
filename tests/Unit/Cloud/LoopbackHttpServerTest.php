<?php

use App\Services\Cloud\LoopbackHttpServer;

/**
 * This is the one place in the OAuth-loopback surface that is deliberately
 * NOT run against a fake — it IS the thing FakeLoopbackHttpServer stands in
 * for everywhere else (OAuthLoopbackClientTest, etc.). A real socket on
 * 127.0.0.1, no external network, nothing that shouldn't run in CI.
 *
 * A client can connect() the instant listen() returns — the OS queues it in
 * the listen backlog — so a plain synchronous stream_socket_client() call
 * before awaitCallback() is enough; no threading/forking needed.
 */
function sendLoopbackRequest(int $port, string $path): void
{
    $client = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5);
    fwrite($client, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    fclose($client);
}

test('receives a real callback request and parses the code and state', function (): void {
    $server = new LoopbackHttpServer;
    $port = $server->listen();

    sendLoopbackRequest($port, '/callback?code=abc123&state=xyz789');

    $result = $server->awaitCallback(5);
    $server->close();

    expect($result)->toBe(['code' => 'abc123', 'state' => 'xyz789']);
});

test('ignores a non-callback request (e.g. a favicon probe) and keeps waiting for the real one', function (): void {
    $server = new LoopbackHttpServer;
    $port = $server->listen();

    sendLoopbackRequest($port, '/favicon.ico');
    sendLoopbackRequest($port, '/callback?code=real-code&state=real-state');

    $result = $server->awaitCallback(5);
    $server->close();

    expect($result)->toBe(['code' => 'real-code', 'state' => 'real-state']);
});

test('returns null when nothing connects before the timeout', function (): void {
    $server = new LoopbackHttpServer;
    $server->listen();

    $result = $server->awaitCallback(1);
    $server->close();

    expect($result)->toBeNull();
});

test('listen binds a real, usable port', function (): void {
    $server = new LoopbackHttpServer;
    $port = $server->listen();

    expect($port)->toBeGreaterThan(0)->toBeLessThan(65536);

    $server->close();
});
