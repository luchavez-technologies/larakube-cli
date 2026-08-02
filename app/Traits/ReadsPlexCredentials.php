<?php

namespace App\Traits;

use App\Data\ConfigData;

trait ReadsPlexCredentials
{
    /**
     * The Commons credentials this project joined for $env, read from its env
     * file (.env for local, .env.{env} otherwise). The DB password is absent
     * once OpenBao manages it (writeTenantConfig strips it from .env) — the
     * live value lives in OpenBao/the synced K8s Secret instead. Returns a
     * structured array keyed by resource (database / redis / s3), each
     * holding only the fields present, or [] when the project isn't a Plex
     * tenant for $env or the env file is missing.
     *
     * @return array<string, array<string, string>>
     */
    protected function plexTenantCredentials(ConfigData $config, string $projectPath, string $env): array
    {
        if ($config->getPlex($env) === []) {
            return [];   // not a Plex tenant for this environment
        }

        $file = $env === 'local' ? $projectPath.'/.env' : $projectPath.'/.env.'.$env;
        if (! is_file($file)) {
            return [];
        }

        $vars = $this->parsePlexEnvVars((string) file_get_contents($file));

        $creds = [];

        if (($vars['DB_HOST'] ?? '') !== '') {
            $creds['database'] = array_filter([
                'Host' => $vars['DB_HOST'].(isset($vars['DB_PORT']) ? ':'.$vars['DB_PORT'] : ''),
                'Database' => $vars['DB_DATABASE'] ?? '',
                'Username' => $vars['DB_USERNAME'] ?? '',
                'Password' => $vars['DB_PASSWORD'] ?? '',
            ], fn ($v) => $v !== '');
        }

        if (($vars['REDIS_HOST'] ?? '') !== '') {
            $creds['redis'] = array_filter([
                'Host' => $vars['REDIS_HOST'].(isset($vars['REDIS_PORT']) ? ':'.$vars['REDIS_PORT'] : ''),
                'DB index' => $vars['REDIS_DB'] ?? '',
                'Password' => ($vars['REDIS_PASSWORD'] ?? 'null') === 'null' ? '' : $vars['REDIS_PASSWORD'],
            ], fn ($v) => $v !== '');
        }

        if (($vars['AWS_BUCKET'] ?? '') !== '') {
            $creds['s3'] = array_filter([
                'Bucket' => $vars['AWS_BUCKET'] ?? '',
                'Endpoint' => $vars['AWS_ENDPOINT'] ?? '',
                'Access key' => $vars['AWS_ACCESS_KEY_ID'] ?? '',
                'Secret' => $vars['AWS_SECRET_ACCESS_KEY'] ?? '',
            ], fn ($v) => $v !== '');
        }

        return $creds;
    }

    /**
     * Minimal KEY=VALUE parser for reading joined Commons creds from a .env file.
     * Skips comments/blank lines and strips matching surrounding quotes.
     *
     * @return array<string, string>
     */
    private function parsePlexEnvVars(string $content): array
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
}
