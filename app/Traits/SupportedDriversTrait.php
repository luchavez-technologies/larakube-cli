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
            ClusterTool::GIT => [CacheDriver::REDIS, CacheDriver::VALKEY],
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
                    StorageDriver::S3,
                ],
                default => StorageDriver::cases(),
            };
        }

        return match ($subject) {
            ClusterTool::DRIVE, ClusterTool::SIGN, ClusterTool::NOTES => [
                StorageDriver::SEAWEEDFS,
                StorageDriver::MINIO,
                StorageDriver::GARAGE,
                StorageDriver::S3,
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
}
