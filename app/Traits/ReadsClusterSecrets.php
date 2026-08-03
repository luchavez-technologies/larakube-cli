<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

trait ReadsClusterSecrets
{
    /**
     * Read a single key from a Kubernetes Secret, base64-decoded. Null when
     * the Secret or key doesn't exist. The canonical implementation of a
     * shape every `{tool}:init`/`{tool}:show` command needs — read back a
     * previously-generated credential so re-running never rotates it.
     */
    protected function readClusterSecretKey(string $kubectl, string $ns, string $secret, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret {$secret} -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }
}
