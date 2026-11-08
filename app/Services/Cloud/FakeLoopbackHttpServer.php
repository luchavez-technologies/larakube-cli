<?php

namespace App\Services\Cloud;

/**
 * Test double for LoopbackHttpServerInterface — no real socket, no real
 * browser. begin() returns the real, randomly-generated `state` to the test
 * (via the PendingOAuthLogin it hands back), so a test queues the exact
 * response it wants awaitCallback() to return — a matching code+state, a
 * forged/mismatched state, or null for a timeout — before calling awaitCode().
 */
final class FakeLoopbackHttpServer implements LoopbackHttpServerInterface
{
    private const int FAKE_PORT = 51204;

    /** @var array{code: ?string, state: ?string}|null */
    private ?array $queuedResponse = null;

    public function listen(): int
    {
        return self::FAKE_PORT;
    }

    /** @param  array{code: ?string, state: ?string}|null  $response */
    public function queueResponse(?array $response): void
    {
        $this->queuedResponse = $response;
    }

    public function awaitCallback(int $timeoutSeconds): ?array
    {
        return $this->queuedResponse;
    }

    public function close(): void {}
}
