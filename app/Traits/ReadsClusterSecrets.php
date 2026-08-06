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
        // Secret keys may legitimately contain dots (consumers.json,
        // homeserver.yaml) and jsonpath treats an unescaped dot as a path
        // separator — so `{.data.consumers.json}` silently matches nothing and
        // reads as "key absent". Callers then regenerate the credential this
        // method exists to preserve, which is a silent data loss, not an error.
        //
        // Idempotent: some callers pre-escaped the dot themselves to work around
        // the above, and escaping that a second time is just as broken.
        $path = str_replace('.', '\.', str_replace('\.', '.', $key));

        $out = trim(Process::run(
            "{$kubectl} get secret {$secret} -n {$ns} -o jsonpath='{.data.{$path}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }
}
