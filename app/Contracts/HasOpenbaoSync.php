<?php

namespace App\Contracts;

/** The Kubernetes Secret + keys OpenBao syncs into for a vendor that stores its own credentials there. */
interface HasOpenbaoSync
{
    /**
     * Schema WITHOUT 'namespace' (injected by ClusterTool, unchanged from today).
     *
     * @return array{secret: string, keys: list<string>}
     */
    public function openbaoSyncConfig(): array;
}
