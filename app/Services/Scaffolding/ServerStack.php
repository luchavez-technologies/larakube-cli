<?php

namespace App\Services\Scaffolding;

use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;

/**
 * What the server frameworks (Django, FastAPI, NestJS, ...) offer for their
 * database, cache, object storage and search, and what each starts with. The
 * wizard asks from this and `new:frameworks` describes it, so they cannot differ.
 */
final class ServerStack
{
    /** @return list<DatabaseDriver> the first is the default */
    public static function databases(AppFramework $framework): array
    {
        return $framework === AppFramework::WORDPRESS
            ? [DatabaseDriver::MYSQL, DatabaseDriver::MARIADB]
            : [DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];
    }

    /** @return list<CacheDriver> the first is the default */
    public static function caches(AppFramework $framework): array
    {
        return $framework === AppFramework::DJANGO
            ? [CacheDriver::REDIS, CacheDriver::MEMCACHED, CacheDriver::DATABASE]
            : [CacheDriver::REDIS, CacheDriver::MEMCACHED];
    }

    /** @return list<StorageDriver> the first is the default */
    public static function storages(): array
    {
        return [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];
    }

    /** WordPress offloads media to S3, so it has no "none". */
    public static function storageIsOptional(AppFramework $framework): bool
    {
        return $framework !== AppFramework::WORDPRESS;
    }

    /** @return list<SearchDriver> */
    public static function searches(AppFramework $framework): array
    {
        return $framework === AppFramework::WORDPRESS
            ? [SearchDriver::TYPESENSE, SearchDriver::MEILISEARCH]
            : [SearchDriver::MEILISEARCH, SearchDriver::TYPESENSE];
    }

    /** Frameworks that follow this stack. */
    public static function covers(AppFramework $framework): bool
    {
        return in_array($framework, [
            AppFramework::DJANGO, AppFramework::FASTAPI, AppFramework::NESTJS, AppFramework::ADONISJS,
            AppFramework::SPRINGBOOT, AppFramework::DOTNET, AppFramework::GIN, AppFramework::AXUM,
            AppFramework::WORDPRESS,
        ], true);
    }
}
