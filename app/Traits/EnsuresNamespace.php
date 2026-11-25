<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

trait EnsuresNamespace
{
    /**
     * Create the namespace if it is missing; safe to re-run. Needs a cluster-admin context,
     * because a project's scoped ServiceAccount can't create namespaces.
     */
    protected function ensureNamespace(string $kubectl, string $namespace): void
    {
        $ns = escapeshellarg($namespace);
        Process::run("{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -");
    }
}
