<?php

namespace App\Enums;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;

enum CompanionDriver: string
{
    public function getLabel(): string
    {
        return match ($this) {
            self::ADMINER => 'Adminer',
            self::PHPMYADMIN => 'phpMyAdmin',
            self::PGADMIN => 'pgAdmin',
            self::REDISINSIGHT => 'RedisInsight',
            self::MONGO_EXPRESS => 'Mongo Express',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::ADMINER => 'Lightweight admin for MySQL, MariaDB, PostgreSQL, and more (recommended)',
            self::PHPMYADMIN => 'Full-featured MySQL/MariaDB admin in arbitrary-server mode',
            self::PGADMIN => 'Full-featured PostgreSQL admin interface',
            self::REDISINSIGHT => 'GUI for Redis — browse keys, run commands, inspect streams',
            self::MONGO_EXPRESS => 'Web-based MongoDB admin interface',
        };
    }

    /**
     * Pinned, never `latest`: a pin says what runs, lets `companion:update`
     * tell an outdated companion from a current one, and keeps a restart from
     * jumping a major version. Check each project's releases before bumping.
     */
    public function getImage(): string
    {
        return match ($this) {
            self::ADMINER => 'adminer:6.3.0',
            self::PHPMYADMIN => 'phpmyadmin:5.2.3',
            self::PGADMIN => 'dpage/pgadmin4:9.19.0',
            self::REDISINSIGHT => 'redis/redisinsight:3.10.1',
            self::MONGO_EXPRESS => 'mongo-express:1.0.2',
        };
    }

    /** HTTP UI port the container listens on. */
    public function getPort(): int
    {
        return match ($this) {
            self::ADMINER => 8080,
            self::PHPMYADMIN => 80,
            self::PGADMIN => 80,
            self::REDISINSIGHT => 5540,
            self::MONGO_EXPRESS => 8081,
        };
    }

    /** Extra ports beyond the HTTP UI port. */
    public function getAdditionalPorts(): array
    {
        return [];
    }

    public function getEnv(): array
    {
        return match ($this) {
            self::PHPMYADMIN => [
                'PMA_ARBITRARY' => '1',
            ],
            self::PGADMIN => [
                'PGADMIN_DEFAULT_EMAIL' => 'admin@larakube.dev',
                'PGADMIN_DEFAULT_PASSWORD' => 'larakube',
                'PGADMIN_CONFIG_SERVER_MODE' => 'False',
            ],
            self::MONGO_EXPRESS => [
                'ME_CONFIG_MONGODB_ENABLE_ADMIN' => 'true',
                'ME_CONFIG_BASICAUTH' => 'false',
            ],
            default => [],
        };
    }

    public function getUrl(): string
    {
        return 'https://'.$this->value.'.'.GlobalConfigData::load()->getLocalTld();
    }

    /**
     * For SQL-type admins, the user-visible hint on how to connect to any project.
     * Returns the cross-namespace DNS pattern they should type as the host.
     */
    public function getConnectionHint(): ?string
    {
        return match ($this) {
            self::ADMINER, self::PHPMYADMIN => 'mysql.{appname}.svc.cluster.local',
            self::PGADMIN => 'postgres.{appname}.svc.cluster.local',
            self::REDISINSIGHT => 'redis.{appname}.svc.cluster.local',
            self::MONGO_EXPRESS => 'mongodb.{appname}.svc.cluster.local',
            default => null,
        };
    }

    public static function installable(): array
    {
        return self::cases();
    }

    /**
     * Companions worth recommending for a project's backing services, ordered
     * best-first and de-duplicated. The dedicated UI leads; Adminer trails as
     * the lighter cross-engine fallback. SQLite has no companion.
     *
     * @return array<int, self>
     */
    public static function recommendedFor(ConfigData $config): array
    {
        $recommended = [];

        foreach ($config->getDatabases() as $database) {
            foreach (match ($database) {
                DatabaseDriver::POSTGRESQL => [self::PGADMIN, self::ADMINER],
                DatabaseDriver::MYSQL, DatabaseDriver::MARIADB => [self::PHPMYADMIN, self::ADMINER],
                DatabaseDriver::MONGODB => [self::MONGO_EXPRESS],
                default => [],
            } as $companion) {
                $recommended[$companion->value] = $companion;
            }
        }

        foreach ($config->getCacheDrivers() as $cache) {
            if ($cache === CacheDriver::REDIS) {
                $recommended[self::REDISINSIGHT->value] = self::REDISINSIGHT;
            }
        }

        return array_values($recommended);
    }

    case ADMINER = 'adminer';
    case PHPMYADMIN = 'phpmyadmin';
    case PGADMIN = 'pgadmin';
    case REDISINSIGHT = 'redisinsight';
    case MONGO_EXPRESS = 'mongo-express';
}
