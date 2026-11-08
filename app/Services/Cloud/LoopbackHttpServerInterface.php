<?php

namespace App\Services\Cloud;

/**
 * The local HTTP listener an OAuth native-app flow redirects back to. Behind an
 * interface specifically so tests can swap in a fake — a real bound socket and
 * an actual browser redirect are not something Process::fake()/Http::fake() can
 * stand in for.
 */
interface LoopbackHttpServerInterface
{
    /** Binds a free local port and returns it. */
    public function listen(): int;

    /**
     * Blocks until the OAuth redirect lands on /callback, or $timeoutSeconds
     * elapses.
     *
     * @return array{code: ?string, state: ?string}|null null on timeout
     */
    public function awaitCallback(int $timeoutSeconds): ?array;

    public function close(): void;
}
