<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

trait PrunesKubeContext
{
    /**
     * Surgically remove a (destroyed) local cluster's entries from ~/.kube/config:
     * its context/cluster/user, and unset current-context if it pointed at one
     * of them.
     *
     * This is why k9s/kubectl can't connect after `cluster:destroy` then
     * `cluster:setup` on WSL: a dangling current-context (and stale entry) lingers,
     * so the only workaround was deleting the whole ~/.kube/config. Unlike that, this
     * leaves every OTHER context (cloud, teammate imports) intact. Best-effort and
     * idempotent — a no-op when there's no kubeconfig or kubectl.
     *
     * @param  array<int, string>  $contexts  Context names to purge (e.g. ['k3s-larakube'])
     */
    protected function pruneKubeContext(array $contexts): void
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (! $home) {
            return;
        }

        $kubeConfig = $home.'/.kube/config';
        if (! file_exists($kubeConfig)) {
            return;
        }

        // Target ~/.kube/config explicitly — that's where cluster:setup merges these
        // entries, and where the stale one lives (a shell $KUBECONFIG could point
        // elsewhere). Mirrors mergeK3sKubeconfig().
        $kc = 'KUBECONFIG='.escapeshellarg($kubeConfig).' kubectl config';
        $current = trim(Process::run($kc.' current-context')->output());

        foreach ($contexts as $ctx) {
            Process::run($kc.' delete-context '.escapeshellarg($ctx));
            Process::run($kc.' delete-cluster '.escapeshellarg($ctx));
            Process::run($kc.' delete-user '.escapeshellarg($ctx));
        }

        // A current-context naming a now-removed cluster makes k9s/kubectl fail to
        // connect; clear it so the next setup (or k9s) starts from a clean slate.
        if ($current !== '' && in_array($current, $contexts, true)) {
            Process::run($kc.' unset current-context');
        }
    }
}
