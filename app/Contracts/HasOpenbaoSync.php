<?php

namespace App\Contracts;

/** The Kubernetes Secret + keys OpenBao syncs into for a vendor that stores its own credentials there. */
interface HasOpenbaoSync
{
    /**
     * Schema WITHOUT 'namespace' (injected by ClusterTool, unchanged from today).
     *
     * `keys` is the simple form, where the OpenBao KV key and the Kubernetes
     * Secret key are the same string — right for the uppercase env-style names
     * every database password already uses.
     *
     * `keyMap` is for the case they cannot be equal: `kvKey => secretKey`. A
     * Deployment that reads `pat` or `setup-key` from its Secret cannot have
     * those be the KV names too, because `production/pat` would collide with
     * every other tool in the same store.
     *
     * $instance lets a vendor put the instance slug in its KV key, so two
     * instances of one tool do not read the same entry.
     *
     * @return array{secret: string, keys?: list<string>, keyMap?: array<string, string>}
     */
    public function openbaoSyncConfig(?string $instance = null): array;
}
