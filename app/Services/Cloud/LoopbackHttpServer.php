<?php

namespace App\Services\Cloud;

use RuntimeException;

/**
 * A single-request HTTP listener on 127.0.0.1, just enough to receive one
 * OAuth redirect (`GET /callback?code=...&state=...`) and reply with a page
 * telling the person they can close the tab. Not a general web server: every
 * other request (a favicon probe, a stray retry) is read and discarded, and
 * the socket closes for good the moment a /callback request is matched.
 */
final class LoopbackHttpServer implements LoopbackHttpServerInterface
{
    /** @var resource|null */
    private $socket;

    public function listen(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException("Could not bind a local port for the OAuth callback: {$errstr}");
        }

        $this->socket = $socket;

        $name = stream_socket_get_name($socket, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        if ($port === 0) {
            throw new RuntimeException('Could not determine the bound loopback port.');
        }

        return $port;
    }

    public function awaitCallback(int $timeoutSeconds): ?array
    {
        if ($this->socket === null) {
            throw new RuntimeException('listen() must be called before awaitCallback().');
        }

        $deadline = now()->addSeconds($timeoutSeconds);

        while (now()->lessThan($deadline)) {
            $read = [$this->socket];
            $write = $except = [];
            $remaining = max(0, now()->diffInSeconds($deadline, absolute: true));
            $changed = @stream_select($read, $write, $except, min($remaining, 1));

            if ($changed === false || $changed === 0) {
                continue;
            }

            $connection = @stream_socket_accept($this->socket, 1);

            if ($connection === false) {
                continue;
            }

            $result = $this->handleConnection($connection);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /** @return array{code: ?string, state: ?string}|null null when this connection wasn't the real callback (keep waiting) */
    private function handleConnection($connection): ?array
    {
        $requestLine = fgets($connection) ?: '';

        // Drain the remaining request headers so the browser's connection doesn't
        // hang waiting for us to finish reading before it accepts our response.
        while (($line = fgets($connection)) !== false && trim($line) !== '') {
            // no-op
        }

        $this->respond($connection);
        fclose($connection);

        if (preg_match('#^GET\s+/callback(?:\?(\S*))?\s#', $requestLine, $match) !== 1) {
            return null;
        }

        parse_str($match[1] ?? '', $query);

        return [
            'code' => is_string($query['code'] ?? null) ? $query['code'] : null,
            'state' => is_string($query['state'] ?? null) ? $query['state'] : null,
        ];
    }

    private function respond($connection): void
    {
        $body = '<!doctype html><html><body style="font-family:sans-serif;text-align:center;padding:4rem">'
            .'<h1>Signed in</h1><p>You can close this tab and return to LaraKube.</p></body></html>';

        $response = "HTTP/1.1 200 OK\r\n"
            ."Content-Type: text/html; charset=utf-8\r\n"
            .'Content-Length: '.strlen($body)."\r\n"
            ."Connection: close\r\n\r\n"
            .$body;

        fwrite($connection, $response);
    }
}
