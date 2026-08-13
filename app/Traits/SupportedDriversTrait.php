<?php

namespace App\Traits;

use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;

trait SupportedDriversTrait
{
    /**
     * Get supported database drivers for an AppFramework or ClusterTool.
     *
     * @return list<DatabaseDriver>
     */
    public function getSupportedDatabaseDrivers(AppFramework|ClusterTool $subject): array
    {
        if ($subject instanceof AppFramework) {
            return match ($subject) {
                AppFramework::LARAVEL => [
                    DatabaseDriver::MYSQL,
                    DatabaseDriver::MARIADB,
                    DatabaseDriver::POSTGRESQL,
                    DatabaseDriver::SQLITE,
                ],
                AppFramework::STATAMIC => [
                    DatabaseDriver::MYSQL,
                    DatabaseDriver::MARIADB,
                    DatabaseDriver::POSTGRESQL,
                    DatabaseDriver::SQLITE,
                ],
                AppFramework::WORDPRESS => [
                    DatabaseDriver::MYSQL,
                    DatabaseDriver::MARIADB,
                ],
                AppFramework::DJANGO, AppFramework::FASTAPI => [
                    DatabaseDriver::POSTGRESQL,
                    DatabaseDriver::MYSQL,
                    DatabaseDriver::SQLITE,
                ],
                AppFramework::NEXTJS, AppFramework::NESTJS, AppFramework::ADONISJS => [
                    DatabaseDriver::POSTGRESQL,
                    DatabaseDriver::MYSQL,
                    DatabaseDriver::SQLITE,
                ],
                default => DatabaseDriver::cases(),
            };
        }

        return match ($subject) {
            ClusterTool::CHAT => [DatabaseDriver::POSTGRESQL],
            ClusterTool::TASKS, ClusterTool::NOTES, ClusterTool::DESK,
            ClusterTool::CRM, ClusterTool::SIGN, ClusterTool::SUPPORT,
            ClusterTool::LINK, ClusterTool::DATA, ClusterTool::ANALYTICS,
            ClusterTool::FLOW, ClusterTool::SHEETS, ClusterTool::ERRORS,
            ClusterTool::GIT, ClusterTool::INSIGHTS, ClusterTool::SSO => [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL],
            default => DatabaseDriver::cases(),
        };
    }

    /**
     * Get supported cache drivers for an AppFramework or ClusterTool.
     *
     * @return list<CacheDriver>
     */
    public function getSupportedCacheDrivers(AppFramework|ClusterTool $subject): array
    {
        if ($subject instanceof AppFramework) {
            return match ($subject) {
                AppFramework::LARAVEL, AppFramework::STATAMIC => [
                    CacheDriver::REDIS,
                    CacheDriver::VALKEY,
                    CacheDriver::DATABASE,
                    CacheDriver::FILE,
                ],
                default => CacheDriver::cases(),
            };
        }

        return match ($subject) {
            ClusterTool::TASKS, ClusterTool::NOTES, ClusterTool::DESK,
            ClusterTool::SUPPORT, ClusterTool::LINK, ClusterTool::FLOW,
            ClusterTool::GIT => [CacheDriver::REDIS],
            default => CacheDriver::cases(),
        };
    }

    /**
     * Get supported storage drivers for an AppFramework or ClusterTool.
     *
     * @return list<StorageDriver>
     */
    public function getSupportedStorageDrivers(AppFramework|ClusterTool $subject): array
    {
        if ($subject instanceof AppFramework) {
            return match ($subject) {
                AppFramework::LARAVEL, AppFramework::STATAMIC => [
                    StorageDriver::SEAWEEDFS,
                    StorageDriver::MINIO,
                    StorageDriver::GARAGE,
                ],
                default => StorageDriver::cases(),
            };
        }

        return match ($subject) {
            ClusterTool::DRIVE, ClusterTool::SIGN, ClusterTool::NOTES => [
                StorageDriver::SEAWEEDFS,
                StorageDriver::MINIO,
                StorageDriver::GARAGE,
            ],
            default => StorageDriver::cases(),
        };
    }

    /**
     * Get supported search drivers for an AppFramework or ClusterTool.
     *
     * @return list<SearchDriver>
     */
    public function getSupportedSearchDrivers(AppFramework|ClusterTool $subject): array
    {
        if ($subject instanceof AppFramework) {
            return match ($subject) {
                AppFramework::LARAVEL, AppFramework::STATAMIC => [
                    SearchDriver::MEILISEARCH,
                    SearchDriver::DATABASE,
                ],
                default => SearchDriver::cases(),
            };
        }

        return [SearchDriver::MEILISEARCH];
    }

    /**
     * Dynamically resolve the DatabaseDriver for a tool or app framework.
     */
    public function resolveToolDatabaseDriver(
        string $kubectl,
        AppFramework|ClusterTool $subject,
        ?string $explicitDriver = null,
        string $plexNamespace = 'larakube-plex',
    ): ?DatabaseDriver {
        $supported = $this->getSupportedDatabaseDrivers($subject);

        if ($explicitDriver !== null && $explicitDriver !== '') {
            $parsed = DatabaseDriver::tryFrom($explicitDriver);
            if ($parsed !== null && in_array($parsed, $supported, true)) {
                return $parsed;
            }
        }

        if (count($supported) === 1) {
            return $supported[0];
        }

        $raw = \Illuminate\Support\Facades\Process::run(
            "{$kubectl} get configmap plex-commons -n {$plexNamespace} -o jsonpath=".escapeshellarg('{.data.commons\.json}'),
        )->output();
        $decoded = json_decode(trim($raw), true);
        $services = is_array($decoded) ? ($decoded['services'] ?? []) : [];

        $active = [];
        foreach ($supported as $driver) {
            $name = $driver->value;
            $isEnabled = ($services[$name]['enabled'] ?? false) === true;
            if (! $isEnabled && in_array($name, ['postgres', 'mysql', 'mariadb'], true)) {
                $check = trim(\Illuminate\Support\Facades\Process::run("{$kubectl} get deployment {$name} -n {$plexNamespace} --no-headers --ignore-not-found")->output());
                $isEnabled = $check !== '';
            }
            if ($isEnabled) {
                $active[] = $driver;
            }
        }

        if (count($active) === 1) {
            return $active[0];
        }

        if (count($active) > 1 && method_exists($this, 'option') && ! ($this->option('no-interaction') ?? false)) {
            $options = [];
            foreach ($active as $d) {
                $options[$d->value] = $d->getLabel() ?? $d->value;
            }
            $chosen = \Laravel\Prompts\select(
                label: 'Multiple database engines are active in Commons. Which database should this target?',
                options: $options,
            );

            return DatabaseDriver::from($chosen);
        }

        return $active[0] ?? $supported[0] ?? DatabaseDriver::POSTGRESQL;
    }

    /**
     * Dynamically resolve the CacheDriver for a tool or app framework.
     */
    public function resolveToolCacheDriver(
        string $kubectl,
        AppFramework|ClusterTool $subject,
        ?string $explicitDriver = null,
        string $plexNamespace = 'larakube-plex',
    ): ?CacheDriver {
        $supported = $this->getSupportedCacheDrivers($subject);

        if ($explicitDriver !== null && $explicitDriver !== '') {
            $parsed = CacheDriver::tryFrom($explicitDriver);
            if ($parsed !== null && in_array($parsed, $supported, true)) {
                return $parsed;
            }
        }

        if (count($supported) === 1) {
            return $supported[0];
        }

        $active = [];
        foreach ($supported as $driver) {
            $name = $driver->value;
            if (in_array($name, ['redis', 'valkey'], true)) {
                $check = trim(\Illuminate\Support\Facades\Process::run("{$kubectl} get deployment {$name} -n {$plexNamespace} --no-headers --ignore-not-found")->output());
                if ($check !== '') {
                    $active[] = $driver;
                }
            }
        }

        if (count($active) === 1) {
            return $active[0];
        }

        if (count($active) > 1 && method_exists($this, 'option') && ! ($this->option('no-interaction') ?? false)) {
            $options = [];
            foreach ($active as $d) {
                $options[$d->value] = $d->getLabel() ?? $d->value;
            }
            $chosen = \Laravel\Prompts\select(
                label: 'Multiple cache services are active in Commons. Which cache service should this target?',
                options: $options,
            );

            return CacheDriver::from($chosen);
        }

        return $active[0] ?? $supported[0] ?? CacheDriver::REDIS;
    }

    /**
     * Dynamically resolve the StorageDriver for a tool or app framework.
     */
    public function resolveToolStorageDriver(
        string $kubectl,
        AppFramework|ClusterTool $subject,
        ?string $explicitDriver = null,
        string $plexNamespace = 'larakube-plex',
    ): ?StorageDriver {
        $supported = $this->getSupportedStorageDrivers($subject);

        if ($explicitDriver !== null && $explicitDriver !== '') {
            $parsed = StorageDriver::tryFrom($explicitDriver);
            if ($parsed !== null && in_array($parsed, $supported, true)) {
                return $parsed;
            }
        }

        if (count($supported) === 1) {
            return $supported[0];
        }

        $active = [];
        foreach ($supported as $driver) {
            $name = $driver->value;
            if (in_array($name, ['seaweedfs', 'minio', 'garage'], true)) {
                $check = trim(\Illuminate\Support\Facades\Process::run("{$kubectl} get deployment {$name} -n {$plexNamespace} --no-headers --ignore-not-found")->output());
                if ($check !== '') {
                    $active[] = $driver;
                }
            }
        }

        if (count($active) === 1) {
            return $active[0];
        }

        if (count($active) > 1 && method_exists($this, 'option') && ! ($this->option('no-interaction') ?? false)) {
            $options = [];
            foreach ($active as $d) {
                $options[$d->value] = $d->getLabel() ?? $d->value;
            }
            $chosen = \Laravel\Prompts\select(
                label: 'Multiple object storage services are active in Commons. Which storage service should this target?',
                options: $options,
            );

            return StorageDriver::from($chosen);
        }

        return $active[0] ?? $supported[0] ?? StorageDriver::SEAWEEDFS;
    }

    /**
     * Dynamically resolve the SearchDriver for a tool or app framework.
     */
    public function resolveToolSearchDriver(
        string $kubectl,
        AppFramework|ClusterTool $subject,
        ?string $explicitDriver = null,
        string $plexNamespace = 'larakube-plex',
    ): ?SearchDriver {
        $supported = $this->getSupportedSearchDrivers($subject);

        if ($explicitDriver !== null && $explicitDriver !== '') {
            $parsed = SearchDriver::tryFrom($explicitDriver);
            if ($parsed !== null && in_array($parsed, $supported, true)) {
                return $parsed;
            }
        }

        if (count($supported) === 1) {
            return $supported[0];
        }

        $check = trim(\Illuminate\Support\Facades\Process::run("{$kubectl} get deployment meilisearch -n {$plexNamespace} --no-headers --ignore-not-found")->output());
        if ($check !== '' && in_array(SearchDriver::MEILISEARCH, $supported, true)) {
            return SearchDriver::MEILISEARCH;
        }

        return $supported[0] ?? SearchDriver::MEILISEARCH;
    }
}
