<?php

namespace App\Services\Project;

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use BackedEnum;

/**
 * What a project's database, cache, object storage and search are, where they
 * run, and how the app reaches them, read from its blueprint and the .env of
 * that environment. Secrets are left out unless asked for.
 */
final class BackingServices
{
    /**
     * @param  array<string, string>  $env  the environment's .env as key => value
     * @return list<array<string, mixed>>
     */
    public function describe(ConfigData $config, string $environment, array $env, bool $reveal = false): array
    {
        $database = $config->getDatabase() ?? (array_values($config->getDatabases())[0] ?? null);
        $cache = $config->getCacheDriver();

        return [
            $this->service('database', 'Database', $database, $config, $environment, $this->databaseDetails($database, $env), $reveal, $env),
            $this->service('cache', 'Cache & queues', $cache, $config, $environment, $this->cacheDetails($cache, $env), $reveal, $env),
            $this->service('storage', 'Object storage', $config->getObjectStorage(), $config, $environment, $this->storageDetails($env), $reveal, $env),
            $this->service('search', 'Search', $config->getScoutDriver(), $config, $environment, $this->searchDetails($config->getScoutDriver(), $env), $reveal, $env),
        ];
    }

    /**
     * Read a .env file into key => value, without interpreting anything.
     *
     * @return array<string, string>
     */
    public static function readEnv(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $vars = [];

        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            $vars[trim($key)] = $value;
        }

        return $vars;
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: bool}>  $details  label, .env key, secret
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function service(string $kind, string $label, ?BackedEnum $driver, ConfigData $config, string $environment, array $details, bool $reveal, array $env): array
    {
        $mode = $this->mode($driver, $config, $environment);
        $rows = [];

        if ($mode !== 'none') {
            foreach ($details as $detail) {
                $value = $env[$detail[1]] ?? null;

                if ($value === null || $value === '' || $value === 'null') {
                    continue;
                }

                $secret = ($detail[2] ?? false) === true;
                $rows[] = ['label' => $detail[0], 'value' => $secret && ! $reveal ? null : $value, 'secret' => $secret];
            }
        }

        return [
            'kind' => $kind,
            'label' => $label,
            'driver' => $driver?->value,
            'name' => $driver !== null && method_exists($driver, 'getLabel') ? (string) $driver->getLabel() : null,
            'mode' => $mode,
            'details' => $rows,
        ];
    }

    /**
     * `commons`: shared Plex Commons. `managed`: the cloud provider's own.
     * `pod`: its own pod in the project's namespace. `file`: no server at all.
     */
    private function mode(?BackedEnum $driver, ConfigData $config, string $environment): string
    {
        if ($driver === null) {
            return 'none';
        }

        if ($driver === DatabaseDriver::SQLITE || $driver === CacheDriver::DATABASE) {
            return 'file';
        }

        if ($config->isPlexBacked($driver, $environment)) {
            return 'commons';
        }

        return in_array($driver->value, $config->getManaged($environment), true) ? 'managed' : 'pod';
    }

    /**
     * @param  array<string, string>  $env
     * @return list<array{0: string, 1: string, 2?: bool}>
     */
    private function databaseDetails(?DatabaseDriver $driver, array $env): array
    {
        if ($driver === DatabaseDriver::SQLITE) {
            return [['File', 'DB_DATABASE']];
        }

        return [['Host', 'DB_HOST'], ['Port', 'DB_PORT'], ['Database', 'DB_DATABASE'], ['Username', 'DB_USERNAME'], ['Password', 'DB_PASSWORD', true]];
    }

    /**
     * @param  array<string, string>  $env
     * @return list<array{0: string, 1: string, 2?: bool}>
     */
    private function cacheDetails(CacheDriver $driver, array $env): array
    {
        return match ($driver) {
            CacheDriver::REDIS => [['Host', 'REDIS_HOST'], ['Port', 'REDIS_PORT'], ['Database index', 'REDIS_DB'], ['Password', 'REDIS_PASSWORD', true]],
            CacheDriver::MEMCACHED => [['Host', 'MEMCACHED_HOST']],
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $env
     * @return list<array{0: string, 1: string, 2?: bool}>
     */
    private function storageDetails(array $env): array
    {
        return [['Bucket', 'AWS_BUCKET'], ['Endpoint', 'AWS_ENDPOINT'], ['Public URL', 'AWS_URL'], ['Access key', 'AWS_ACCESS_KEY_ID'], ['Secret key', 'AWS_SECRET_ACCESS_KEY', true]];
    }

    /**
     * @param  array<string, string>  $env
     * @return list<array{0: string, 1: string, 2?: bool}>
     */
    private function searchDetails(?SearchDriver $driver, array $env): array
    {
        return match ($driver) {
            SearchDriver::MEILISEARCH => [['Host', 'MEILISEARCH_HOST'], ['Key', 'MEILISEARCH_KEY', true]],
            SearchDriver::TYPESENSE => [['Host', 'TYPESENSE_HOST'], ['Port', 'TYPESENSE_PORT'], ['API key', 'TYPESENSE_API_KEY', true]],
            default => [],
        };
    }
}
