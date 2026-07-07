<?php

namespace App\Enums;

use App\Contracts\AsDependency;
use App\Contracts\HasArtisanCommands;
use App\Contracts\HasCommandOptions;
use App\Contracts\HasComposerDependencies;
use App\Contracts\HasDockerImage;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHiddenComponents;
use App\Contracts\HasHosts;
use App\Contracts\HasKubernetesFiles;
use App\Contracts\HasLabel;
use App\Contracts\HasLifecycleHooks;
use App\Contracts\HasPodName;
use App\Contracts\HasSelectOptions;
use App\Contracts\PlexProvisionable;
use App\Contracts\RemovableWhenManaged;
use App\Contracts\RequiresPhpExtensions;
use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum DatabaseDriver: string implements AsDependency, HasArtisanCommands, HasCommandOptions, HasComposerDependencies, HasDockerImage, HasEnvironmentVariables, HasHiddenComponents, HasHosts, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasPodName, HasSelectOptions, PlexProvisionable, RemovableWhenManaged, RequiresPhpExtensions
{
    use GeneratesProjectInfrastructure, ProvidesCommandOptions, ProvidesSelectOptions;

    public function getPodName(?ConfigData $config = null): string
    {
        return $this->value;
    }

    public function getLabel(): ?string
    {
        return match ($this) {
            self::MYSQL => 'MySQL',
            self::MARIADB => 'MariaDB',
            self::POSTGRESQL => 'PostgreSQL',
            self::MONGODB => 'MongoDB',
            self::SQLITE => 'SQLite (Local File)',
        };
    }

    public function isHidden(?ConfigData $config = null): bool
    {
        return $this === self::SQLITE && $config?->getServerVariation() === ServerVariation::FRANKENPHP;
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()} database",
            ];
        }

        return $options;
    }

    public static function getDatabases(bool $asValues = false): array
    {
        $databases = [];

        foreach (self::cases() as $case) {
            $databases[] = $asValues ? $case->value : $case;
        }

        return $databases;
    }

    public function updateK8s(ConfigData $config): void
    {
        if ($this === self::SQLITE) {
            return;
        }

        $k8sPath = $config->getK8sPath();

        // Write workload
        $viewName = $this->getWorkloadViewName();
        $dest = $this->getWorkloadYamlDestination();
        if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
            $content = view($viewName, ['config' => $config, 'driver' => $this])->render();
            file_put_contents("$k8sPath/{$dest}", $content);
        }

        // Write volumes
        if ($viewName = $this->getStorageViewName()) {
            $storageDest = $this->getStorageYamlDestination();

            foreach ($config->getEnvironments() as $env) {
                // Skip envs where this service is externally managed — it has
                // no in-cluster volumes there (a delete-patch removes the
                // workload instead).
                if (in_array($this->value, $config->getManaged($env), true)) {
                    continue;
                }
                $dest = "overlays/$env/{$storageDest}";
                if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                    $vols = view($viewName, ['config' => $config, 'driver' => $this, 'environment' => $env])->render();
                    file_put_contents("$k8sPath/{$dest}", $vols);
                }
            }
        }

        // Write for local only
        if ($viewName = $this->getPatchViewName()) {
            $dest = $this->getPatchYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $patch = view($viewName, ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$dest}", $patch);
            }
        }
    }

    public function getWorkloadViewName(): ?string
    {
        return match ($this) {
            self::MYSQL => 'k8s.mysql.deployment',
            self::MARIADB => 'k8s.mariadb.deployment',
            self::POSTGRESQL => 'k8s.postgres.deployment',
            self::MONGODB => 'k8s.mongodb.statefulset',
            self::SQLITE => null,
        };
    }

    public function getWorkloadYamlDestination(): ?string
    {
        return match ($this) {
            self::MYSQL => 'base/mysql-deployment.yaml',
            self::MARIADB => 'base/mariadb-deployment.yaml',
            self::POSTGRESQL => 'base/postgres-deployment.yaml',
            self::MONGODB => 'base/mongodb-statefulset.yaml',
            self::SQLITE => null,
        };
    }

    public function getNetworkViewName(): ?string
    {
        return null;
    }

    public function getNetworkYamlDestination(): ?string
    {
        return null;
    }

    public function getStorageViewName(): ?string
    {
        return match ($this) {
            self::MYSQL => 'k8s.mysql.volumes',
            self::MARIADB => 'k8s.mariadb.volumes',
            self::POSTGRESQL => 'k8s.postgres.volumes',
            default => null,
        };
    }

    public function getStorageYamlDestination(): ?string
    {
        return match ($this) {
            self::MYSQL => 'mysql-volumes.yaml',
            self::MARIADB => 'mariadb-volumes.yaml',
            self::POSTGRESQL => 'postgres-volumes.yaml',
            default => null,
        };
    }

    public function getPatchViewName(): ?string
    {
        return match ($this) {
            self::MYSQL => 'k8s.mysql.patch',
            self::MARIADB => 'k8s.mariadb.patch',
            self::POSTGRESQL => 'k8s.postgres.patch',
            default => null,
        };
    }

    public function getPatchYamlDestination(): ?string
    {
        return match ($this) {
            self::MYSQL => 'overlays/local/mysql-patch.yaml',
            self::MARIADB => 'overlays/local/mariadb-patch.yaml',
            self::POSTGRESQL => 'overlays/local/postgres-patch.yaml',
            default => null,
        };
    }

    public function getK8sDeploymentArgs(): string
    {
        return '';
    }

    public function getManifestFiles(?ConfigData $config = null): array
    {
        $manifests = match ($this) {
            self::MYSQL => [
                'base' => ['mysql-deployment.yaml'],
                'local' => ['mysql-volumes.yaml'],
                'cloud' => ['mysql-volumes.yaml'],
                'patches' => ['mysql-patch.yaml'],
            ],
            self::MARIADB => [
                'base' => ['mariadb-deployment.yaml'],
                'local' => ['mariadb-volumes.yaml'],
                'cloud' => ['mariadb-volumes.yaml'],
                'patches' => ['mariadb-patch.yaml'],
            ],
            self::POSTGRESQL => [
                'base' => ['postgres-deployment.yaml'],
                'local' => ['postgres-volumes.yaml'],
                'cloud' => ['postgres-volumes.yaml'],
                'patches' => ['postgres-patch.yaml'],
            ],
            self::MONGODB => [
                'base' => ['mongodb-statefulset.yaml'],
            ],
            self::SQLITE => [],
        };

        return $manifests;
    }

    public function getManagedResources(ConfigData $config): array
    {
        if ($this === self::SQLITE) {
            return [];
        }

        $name = $this->getPodName($config);

        return [
            ['kind' => $this === self::MONGODB ? 'StatefulSet' : 'Deployment', 'name' => $name],
            ['kind' => 'Service', 'name' => $name],
        ];
    }

    public function getDockerImage(?ConfigData $config = null): string
    {
        return match ($this) {
            self::MYSQL => 'mysql:8.4',
            self::MARIADB => 'mariadb:11.8',
            self::POSTGRESQL => ($config?->hasFeature(LaravelFeature::AI)) ? 'pgvector/pgvector:pg17' : 'postgres:17.9',
            self::MONGODB => 'mongo:7.0',
            self::SQLITE => '',
        };
    }

    public function getEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return array_merge(
            $this->getPublicEnvironmentVariables($config, $environment),
            $this->getSecretEnvironmentVariables($config, $environment),
        );
    }

    public function getPublicEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        if ($this === self::SQLITE) {
            return [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => '/var/lib/larakube/database.sqlite',
                'AUTORUN_LARAVEL_MIGRATION_SKIP_DB_CHECK' => 'true',
            ];
        }

        $envs = [
            'DB_CONNECTION' => $this->dbConnection(),
            'DB_HOST' => $config ? $config->getInternalFqdn($this, $environment) : $this->dbHost(),
            'DB_PORT' => (string) $this->dbPort(),
            'DB_DATABASE' => 'laravel',
            'DB_USERNAME' => $this->dbUsername(),
        ];

        if ($this === self::MONGODB) {
            $envs['AUTORUN_LARAVEL_MIGRATION_SKIP_DB_CHECK'] = 'true';
        }

        return $envs;
    }

    public function getSecretEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        if ($this === self::SQLITE) {
            return [];
        }

        $envs = [
            'DB_PASSWORD' => 'larakubesecretpassword',
        ];

        if ($this === self::MONGODB) {
            $host = $config ? $config->getInternalFqdn($this, $environment) : 'mongodb';
            $envs['DB_URI'] = "mongodb://root:larakubesecretpassword@{$host}:27017/laravel?authSource=admin";
        }

        return $envs;
    }

    public function getHosts(ConfigData $config, string $environment = 'local'): array
    {
        // Databases publish no ingress host of their own. Admin consoles are no
        // longer per-project — they're the shared CompanionDriver apps in
        // larakube-companions (phpmyadmin.kube, etc.), surfaced via ManagesCompanions,
        // not a `mariadb.<project>.kube` route here. See showCompanionAccess().
        return [];
    }

    /**
     * Database consoles aren't user-overrideable — they're either the
     * baked-in local .kube domains or absent (in cloud envs).
     */
    public function getHostServices(): array
    {
        return [];
    }

    public function getDependencyConfig(ConfigData $config): array
    {
        if ($this === self::SQLITE) {
            return [];
        }

        return [$this->dbHost() => $this->dbPort()];
    }

    public function dbConnection(): string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 'mysql',
            self::POSTGRESQL => 'pgsql',
            self::MONGODB => 'mongodb',
            self::SQLITE => 'sqlite',
        };
    }

    public function dbHost(): string
    {
        return $this->getPodName();
    }

    public function dbPort(): int
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 3306,
            self::POSTGRESQL => 5432,
            self::MONGODB => 27017,
            self::SQLITE => 0,
        };
    }

    public function dbUsername(): ?string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 'laravel',
            self::POSTGRESQL => 'postgres',
            self::MONGODB => 'root',
            self::SQLITE => null,
            default => null,
        };
    }

    public function isExternal(): bool
    {
        return $this !== self::SQLITE;
    }

    /**
     * Shell command (intended to run inside this driver's pod via kubectl exec)
     * that idempotently creates a test database. Returns null for drivers where
     * `larakube test --with-db` doesn't apply (SQLite uses :memory:; MongoDB
     * auto-creates collections on first write).
     *
     * Returned command uses the DB pod's own credentials env vars
     * (POSTGRES_USER for Postgres superuser, MYSQL_ROOT_PASSWORD for MySQL root).
     * Wrap with `sh -c "..."` when invoking — relies on shell pipe/||/$VAR.
     */
    public function getTestDatabaseProvisionCommand(string $testDbName): ?string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => sprintf(
                'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e \'CREATE DATABASE IF NOT EXISTS `%s`\'',
                $testDbName,
            ),
            self::POSTGRESQL => sprintf(
                'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "SELECT 1 FROM pg_database WHERE datname=\'%s\'" | grep -q 1 || PGPASSWORD="$POSTGRES_PASSWORD" createdb -U "$POSTGRES_USER" "%s"',
                $testDbName,
                $testDbName,
            ),
            default => null,
        };
    }

    public function getComposerDependencies(?ConfigData $context = null): array
    {
        return match ($this) {
            self::MONGODB => ['mongodb/laravel-mongodb'],
            default => [],
        };
    }

    public function getPhpExtensions(): array
    {
        return match ($this) {
            self::MONGODB => ['mongodb'],
            default => [],
        };
    }

    public function onPostInstall(string $projectPath, ?ConfigData $context = null): void
    {
        $this->syncEnvFile($projectPath, $this->getEnvironmentVariables($context));

        if ($this === self::MONGODB) {
            $configPath = "$projectPath/config/database.php";
            if (file_exists($configPath)) {
                $content = file_get_contents($configPath);

                if (! str_contains($content, "'mongodb'")) {
                    $stub = "
        'mongodb' => [
            'driver' => 'mongodb',
            'dsn' => env('DB_URI', 'mongodb://127.0.0.1:27017/laravel'),
            'database' => env('DB_DATABASE', 'laravel'),
        ],\n";

                    // Inject before sqlsrv or before the end of the connections array
                    if (str_contains($content, "'sqlsrv' => [")) {
                        $content = str_replace("'sqlsrv' => [", $stub."\n        'sqlsrv' => [", $content);
                    } else {
                        // Fallback: search for the end of the connections array
                        $content = preg_replace("/('connections' => \[.*?)(\n\s+\])/s", "$1$stub$2", $content);
                    }

                    file_put_contents($configPath, $content);
                }
            }
        }
    }

    public function getPostInstallInstructions(?ConfigData $config = null): array
    {
        return match ($this) {
            self::MONGODB => [
                'MongoDB is a schema-less document store. Integration with Laravel requires specific code changes:',
                '1. IMPORTANT: Follow the Official Guide: https://www.mongodb.com/docs/drivers/php/laravel-mongodb/current/',
                '2. Remove positional methods like "->after(\'column\')" from all migration files.',
                '3. Update your Models (including User.php) to extend "MongoDB\Laravel\Eloquent\Model".',
                '4. Ensure your Authenticatable models (User.php) use "MongoDB\Laravel\Auth\User" as a base.',
            ],
            default => [],
        };
    }

    public function getCommandOption(): ?string
    {
        return match ($this) {
            self::MYSQL => 'mysql',
            self::MARIADB => 'mariadb',
            self::POSTGRESQL => 'postgres',
            self::MONGODB => 'mongodb',
            self::SQLITE => 'sqlite',
        };
    }

    public function getArtisanCommands(?ConfigData $context = null): array
    {
        return [];
    }

    public function isPlexReady(): bool
    {
        // Relational engines wired as Commons database backends (per-tenant
        // database + login via commonsTenantSql). MongoDB is mapped but not wired.
        return match ($this) {
            self::POSTGRESQL, self::MYSQL, self::MARIADB => true,
            default => false,
        };
    }

    /**
     * SQL that idempotently provisions a tenant's database + login in the shared
     * Commons, piped to this engine's admin client (commonsAdminClient). $db/$user
     * are pre-sanitized identifiers; the password is engine-escaped. Null for
     * engines that aren't a relational Commons backend (SQLite is a local file;
     * MongoDB auto-creates and isn't wired). Pure.
     */
    public function commonsTenantSql(string $db, string $user, string $password): ?string
    {
        return match ($this) {
            self::POSTGRESQL => $this->postgresCommonsCreateSql($db, $user, $password),
            self::MYSQL, self::MARIADB => $this->mysqlCommonsCreateSql($db, $user, $password),
            default => null,
        };
    }

    /**
     * SQL that drops a tenant's database + login (the plex:leave teardown).
     * Mirrors commonsTenantSql. Null for non-relational engines. Pure.
     */
    public function commonsDropSql(string $db, string $user): ?string
    {
        return match ($this) {
            self::POSTGRESQL => implode("\n", [
                "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$db}' AND pid <> pg_backend_pid();",
                "DROP DATABASE IF EXISTS \"{$db}\";",
                "DROP ROLE IF EXISTS \"{$user}\";",
            ]),
            self::MYSQL, self::MARIADB => implode("\n", [
                "DROP DATABASE IF EXISTS `{$db}`;",
                "DROP USER IF EXISTS '{$user}'@'%';",
                'FLUSH PRIVILEGES;',
            ]),
            default => null,
        };
    }

    /**
     * The in-pod client that reads tenant SQL on stdin. Intended to be invoked as
     * `kubectl exec -i deploy/<value> -- sh -c '<this>' < tenant.sql` so the pod's
     * shell expands the password env var. Postgres connects as the local-socket
     * superuser (trust), so it needs no password.
     */
    public function commonsAdminClient(): string
    {
        return match ($this) {
            self::POSTGRESQL => 'psql -U postgres -v ON_ERROR_STOP=1',
            self::MYSQL => 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"',
            self::MARIADB => 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD"',
            default => '',
        };
    }

    /**
     * Dump the self-hosted pod's application database to stdout (used by
     * plex:migrate). The self-hosted app DB is always named "laravel"; env vars
     * (MYSQL_ROOT_PASSWORD / POSTGRES_PASSWORD) are expanded by the pod's shell.
     */
    public function selfHostedDumpCommand(): string
    {
        return match ($this) {
            self::POSTGRESQL => 'pg_dump -U postgres --no-owner --clean --if-exists laravel',
            self::MYSQL => 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" laravel',
            self::MARIADB => 'mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" laravel',
            default => '',
        };
    }

    /**
     * Restore a SQL dump (piped on stdin) into a specific database in the Commons
     * pod. $targetDb is the tenant identifier (already created via commonsTenantSql).
     * $password authenticates AS that tenant for Postgres — restoring as the
     * admin superuser would leave every table the dump creates owned by
     * "postgres" instead of the tenant role, even though
     * postgresCommonsCreateSql() already makes the tenant own the database and
     * public schema: schema ownership doesn't extend to objects a DIFFERENT
     * role's CREATE TABLE statement produces inside it. The tenant's own app
     * connection (a different role again) would then get "permission denied"
     * on its own tables. MySQL/MariaDB have no such gap — GRANT-based
     * privileges apply to any table in the database regardless of who
     * created it, so those two ignore $password and keep using root.
     */
    public function commonsRestoreCommand(string $targetDb, string $password): string
    {
        $db = escapeshellarg($targetDb);
        $role = escapeshellarg($targetDb); // db name === tenant login role, see postgresCommonsCreateSql()

        return match ($this) {
            // -h forces TCP/password auth — the local socket's peer auth would
            // try to match the container's OS user (postgres), not this role.
            self::POSTGRESQL => 'PGPASSWORD='.escapeshellarg($password)." psql -U {$role} -h 127.0.0.1 -v ON_ERROR_STOP=1 -d {$db}",
            self::MYSQL => "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" {$db}",
            self::MARIADB => "mariadb -uroot -p\"\$MYSQL_ROOT_PASSWORD\" {$db}",
            default => '',
        };
    }

    /**
     * Restore a SQL dump (piped on stdin) into the SELF-HOSTED pod's own
     * "laravel" database — the inverse of selfHostedDumpCommand(), used by
     * plex:leave --restore to copy Commons data back after rejoining a
     * self-hosted pod. Same fixed db name selfHostedDumpCommand assumes.
     */
    public function selfHostedRestoreCommand(): string
    {
        return match ($this) {
            self::POSTGRESQL => 'psql -U postgres -v ON_ERROR_STOP=1 -d laravel',
            self::MYSQL => 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" laravel',
            self::MARIADB => 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" laravel',
            default => '',
        };
    }

    /**
     * The in-pod command that dumps a tenant database to stdout (the plex:leave
     * safety backup). $db is a sanitized identifier. Same sh -c invocation as
     * commonsAdminClient so the password env var expands in the pod.
     */
    public function commonsBackupCommand(string $db): string
    {
        return match ($this) {
            self::POSTGRESQL => "pg_dump -U postgres --no-owner {$db}",
            self::MYSQL => "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" {$db}",
            self::MARIADB => "mariadb-dump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" {$db}",
            default => '',
        };
    }

    public function commonsServiceName(): ?string
    {
        // The Commons service name IS the driver value — no remapping. SQLite is
        // a local file, never a shared service.
        return $this === self::SQLITE ? null : $this->value;
    }

    public function exporterImage(): ?string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 'prom/mysqld-exporter:v0.15.1',
            self::POSTGRESQL => 'prometheuscommunity/postgres-exporter:v0.15.0',
            default => null,
        };
    }

    public function exporterPort(): int
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 9104,
            self::POSTGRESQL => 9187,
            default => 0,
        };
    }

    /**
     * Postgres: role first (the db is created OWNED BY it); the tenant owns its
     * database + the public schema so migrations can create tables (PG 15+ locks
     * `public` down for non-owners) — full per-tenant isolation, no shared grants.
     */
    private function postgresCommonsCreateSql(string $db, string $role, string $password): string
    {
        $pw = str_replace("'", "''", $password);

        return implode("\n", [
            "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN CREATE ROLE \"{$role}\" LOGIN PASSWORD '{$pw}'; END IF; END \$\$;",
            "ALTER ROLE \"{$role}\" PASSWORD '{$pw}';",
            "SELECT 'CREATE DATABASE \"{$db}\" OWNER \"{$role}\"' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '{$db}')\\gexec",
            "ALTER DATABASE \"{$db}\" OWNER TO \"{$role}\";",
            "GRANT ALL PRIVILEGES ON DATABASE \"{$db}\" TO \"{$role}\";",
            "\\connect \"{$db}\"",
            "ALTER SCHEMA public OWNER TO \"{$role}\";",
        ]);
    }

    /**
     * MySQL/MariaDB: create the database + a '%'-host login scoped to it
     * (GRANT ... ON `db`.*). Idempotent and re-asserts the password on re-run.
     * utf8mb4 for full Unicode. The engine caps user names at 32 chars — tenant
     * identifiers (app names) are short, but we truncate to stay valid.
     */
    private function mysqlCommonsCreateSql(string $db, string $user, string $password): string
    {
        $pw = addslashes($password);
        $user = substr($user, 0, 32);

        return implode("\n", [
            "CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            "CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY '{$pw}';",
            "ALTER USER '{$user}'@'%' IDENTIFIED BY '{$pw}';",
            "GRANT ALL PRIVILEGES ON `{$db}`.* TO '{$user}'@'%';",
            'FLUSH PRIVILEGES;',
        ]);
    }

    case MYSQL = 'mysql';
    case MARIADB = 'mariadb';
    case POSTGRESQL = 'postgres';
    case MONGODB = 'mongodb';
    case SQLITE = 'sqlite';
}
