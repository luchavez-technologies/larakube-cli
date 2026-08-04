<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * Read env vars from the two sources LaraKube reconciles: a local `.env` body
 * and a cluster ConfigMap/Secret. Shared by companion re-pointing (which needs
 * the effective values) and by `dotenv`/`dotenv:audit` (which compare them).
 */
trait ReadsEnvSources
{
    /**
     * Parse KEY=VALUE pairs from a .env body. Skips comments/blank lines and strips
     * matching surrounding quotes. Adequate for connection vars (no multiline values).
     *
     * @return array<string, string>
     */
    protected function parseDotenvVars(string $content): array
    {
        $vars = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    /**
     * Read a ConfigMap or Secret's `.data` map from the cluster as KEY => value.
     * Secret values are base64-decoded. Returns [] when the object is missing or
     * the cluster is unreachable. `-o json` (not jsonpath '{.data}', which emits
     * non-JSON Go-map syntax) so the payload is parseable. Read-only. $kubectl is
     * the command prefix (e.g. `kubectl --context foo` from contextKubectl());
     * null falls back to plain `kubectl`.
     *
     * @return array<string, string>
     */
    protected function readClusterEnvVars(string $kind, string $name, string $namespace, bool $base64, ?string $kubectl = null): array
    {
        $json = Process::run(
            ($kubectl ?? 'kubectl').' get '.escapeshellarg($kind).' '.escapeshellarg($name)
            .' -n '.escapeshellarg($namespace).' -o json',
        )->output();

        if (trim($json) === '') {
            return [];
        }

        $parsed = json_decode($json, true);
        $data = is_array($parsed) ? ($parsed['data'] ?? null) : null;

        if (! is_array($data)) {
            return [];
        }

        $vars = [];
        foreach ($data as $key => $value) {
            $vars[(string) $key] = $base64 ? (string) base64_decode((string) $value, true) : (string) $value;
        }

        return $vars;
    }

    /**
     * Would this key be stored in the Secret rather than the ConfigMap? Two
     * signals, ORed: it's in $knownSecrets (a driver/feature enum's
     * getSecretEnvironmentVariables(), aggregated by
     * ConfigData::getAllSecretEnvironmentVariables()), or its NAME reads as
     * sensitive. The name check is load-bearing, not a backstop — APP_KEY
     * and any third-party API key a user's own app defines (AIRTABLE_API_KEY,
     * STRIPE_SECRET, …) are never emitted by any component's enum at all, so
     * $knownSecrets alone would silently never classify them as secret. Pure.
     *
     * @param  array<int, string>  $knownSecrets
     */
    protected function isSecretKey(string $key, array $knownSecrets): bool
    {
        return in_array($key, $knownSecrets, true)
            || str_contains($key, 'PASSWORD')
            || str_contains($key, 'SECRET')
            || str_contains($key, 'KEY')
            || str_contains($key, 'TOKEN');
    }
}
