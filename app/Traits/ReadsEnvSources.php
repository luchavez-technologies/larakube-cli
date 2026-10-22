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
     * Read cluster secret vars, checking canonical 'app-secrets' first,
     * then falling back to legacy 'laravel-secrets' for backward compatibility.
     *
     * @return array<string, string>
     */
    protected function readCanonicalSecret(string $namespace, ?string $kubectl = null): array
    {
        $vars = $this->readClusterEnvVars('secret', 'app-secrets', $namespace, true, $kubectl);
        if ($vars !== []) {
            return $vars;
        }

        return $this->readClusterEnvVars('secret', 'laravel-secrets', $namespace, true, $kubectl);
    }

    /**
     * Read cluster config vars, checking canonical 'app-config' first,
     * then falling back to legacy 'laravel-config' for backward compatibility.
     *
     * @return array<string, string>
     */
    protected function readCanonicalConfig(string $namespace, ?string $kubectl = null): array
    {
        $vars = $this->readClusterEnvVars('configmap', 'app-config', $namespace, false, $kubectl);
        if ($vars !== []) {
            return $vars;
        }

        return $this->readClusterEnvVars('configmap', 'laravel-config', $namespace, false, $kubectl);
    }

    /**
     * Resolve the active secret name in the namespace: 'app-secrets' if present, else 'laravel-secrets'.
     */
    protected function resolveSecretName(string $namespace, ?string $kubectl = null, ?\App\Data\ConfigData $config = null): string
    {
        $checkApp = Process::run(
            ($kubectl ?? 'kubectl').' get secret app-secrets -n '.escapeshellarg($namespace).' --ignore-not-found -o name',
        )->output();
        if (trim($checkApp) !== '') {
            return 'app-secrets';
        }

        $checkLaravel = Process::run(
            ($kubectl ?? 'kubectl').' get secret laravel-secrets -n '.escapeshellarg($namespace).' --ignore-not-found -o name',
        )->output();
        if (trim($checkLaravel) !== '') {
            return 'laravel-secrets';
        }

        if ($config !== null && $config->framework !== null && $config->framework !== \App\Enums\AppFramework::LARAVEL) {
            return 'app-secrets';
        }

        return 'laravel-secrets';
    }

    /**
     * Resolve the active configmap name in the namespace: 'app-config' if present, else 'laravel-config'.
     */
    protected function resolveConfigMapName(string $namespace, ?string $kubectl = null, ?\App\Data\ConfigData $config = null): string
    {
        $checkApp = Process::run(
            ($kubectl ?? 'kubectl').' get configmap app-config -n '.escapeshellarg($namespace).' --ignore-not-found -o name',
        )->output();
        if (trim($checkApp) !== '') {
            return 'app-config';
        }

        $checkLaravel = Process::run(
            ($kubectl ?? 'kubectl').' get configmap laravel-config -n '.escapeshellarg($namespace).' --ignore-not-found -o name',
        )->output();
        if (trim($checkLaravel) !== '') {
            return 'laravel-config';
        }

        if ($config !== null && $config->framework !== null && $config->framework !== \App\Enums\AppFramework::LARAVEL) {
            return 'app-config';
        }

        return 'laravel-config';
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

    /**
     * A connection URL that carries its own credentials
     * (postgresql://user:password@host/db, redis://:password@host). Such a
     * value is secret whatever its key is named — DATABASE_URL and REDIS_URL
     * match none of the name patterns above. Pure.
     */
    protected function hasEmbeddedCredentials(string $value): bool
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://[^/@\s]*:[^/@\s]+@#i', trim($value, " \t\"'")) === 1;
    }
}
