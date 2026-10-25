<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Traits\GuardsSharedStorage;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * Turn the multi-node "right answer" into the easy path. On `multi-node-ha`, app
 * pods get per-pod ephemeral storage, so state must be externalized — uploads on
 * object storage (S3/Spaces), sessions/cache off `file`. This command picks an
 * enum-driven cache backend (Redis / Memcached / Database) and rewrites the env's
 * driver-selector keys to match, sourcing the backends one of three ways: join the
 * cluster's shared Plex Commons (offered only when one is actually initialized
 * there), self-host them in-project via `larakube add`, or bring your own managed
 * services. It writes only the selector keys (SESSION_DRIVER/CACHE_STORE/…) — the
 * connection values (AWS_ / REDIS_ / MEMCACHED_) are owned by those flows.
 */
class CloudExternalizeCommand extends Command
{
    use GuardsSharedStorage, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    /**
     * App-level driver-selector keys we own here. The CacheDriver enum decides
     * their VALUES (redis/memcached/database); the matching connection vars it also
     * emits (REDIS_HOST, …) are deliberately excluded so a Commons/self-host
     * connection is never clobbered.
     */
    private const DRIVER_SELECTOR_KEYS = [
        'SESSION_DRIVER',
        'CACHE_STORE',
        'QUEUE_CONNECTION',
        'APP_MAINTENANCE_DRIVER',
        'APP_MAINTENANCE_STORE',
    ];

    protected $signature = 'cloud:externalize
                            {environment? : The environment to externalize (omit to pick from the project)}';

    protected $description = 'Externalize a multi-node environment — uploads on S3, sessions/cache on Redis/Memcached or the database';

    public function handle(): int
    {
        $this->renderHeader();

        $config = $this->getProjectConfig(getcwd());
        if ($config === null) {
            $this->laraKubeError('Run this inside a LaraKube project.');

            return 1;
        }

        $arg = (string) ($this->argument('environment') ?? '');
        if ($arg !== '' && $config->getEnvironment($arg) === null) {
            $this->laraKubeError("Unknown environment '{$arg}'. Pick one of: ".implode(', ', $config->getCloudEnvironments()));

            return 1;
        }

        $env = $arg !== '' ? $arg : $this->pickEnvironment($config);
        if ($env === null) {
            $this->laraKubeError('This project has no cloud environments yet — add one with `larakube env <name>`.');

            return 1;
        }

        $envFile = $config->getPath().'/.env.'.$env;
        if (! is_file($envFile)) {
            $this->laraKubeError(".env.{$env} not found — run `larakube cloud:configure {$env}` first.");

            return 1;
        }

        $this->laraKubeInfo("Externalize state for '{$env}' (multi-node uses per-pod ephemeral storage)");
        $this->newLine();

        $risky = $this->localStateDrivers($config, $env);
        if ($risky === []) {
            $this->laraKubeInfo('✅ Already externalized — uploads on object storage, sessions/cache off `file`. Nothing to do.');

            return 0;
        }

        $this->line('  Still on local storage:');
        foreach ($risky as $r) {
            $this->line("    • <fg=yellow>{$r}</>");
        }
        $this->newLine();

        // SQLite can't span nodes (its DB is a file on an RWO volume), so no amount
        // of externalizing uploads/cache makes this env multi-node-ready. Offer the
        // switch up front, reusing `larakube add` (DatabaseDriver owns the wiring).
        $db = $config->getDatabase();
        if ($db !== null && ! $db->isExternal()) {
            $this->laraKubeWarn("'{$env}' uses {$db->getLabel()} — a file database that can't run multi-node.");
            $this->line('  Multi-node (and joining a Commons) needs a networked engine; externalizing uploads/cache alone won\'t fix it.');
            $this->line('  This just <fg=white>declares</> the engine — where it actually RUNS is your backend choice below:');
            $this->line('   • <fg=cyan>Commons</> → it hosts the engine for you (this declaration is converted to Commons-backed)');
            $this->line('   • <fg=cyan>self-host</> → it stays in-project');
            $this->line('   • <fg=cyan>managed</> → point DB_* at your own (e.g. DO Managed Postgres)');
            $this->newLine();

            if (confirm('Switch to a networked database engine now (via `larakube feature:add`)?', default: true)) {
                $this->call('feature:add', ['items' => [select(
                    label: 'Which database engine?',
                    options: $this->networkedDatabaseOptions(),
                    default: DatabaseDriver::POSTGRESQL->value,
                )]]);
                $config = $this->getProjectConfig(getcwd());
            } else {
                $this->laraKubeWarn("Leaving SQLite in place — '{$env}' still won't run multi-node until you switch it.");
            }
            $this->newLine();
        }

        // Sessions & cache backend — enum-driven (Redis / Memcached / Database),
        // never a hardcoded driver string. Defaults to the project's cache driver.
        $cache = $this->chooseCacheDriver($config);

        // Source the backends: object storage (S3) + the chosen cache's service
        // (Database needs none — it reuses the primary DB).
        [$config, $hasS3, $cacheReady, $managed] = $this->resolveBackends($config, $env, $cache);

        // Couldn't get the service the chosen cache needs → fall back to Database.
        if (! $cacheReady && $cache->commonsServiceName() !== null) {
            $this->laraKubeWarn("No {$cache->getLabel()} backend wired for this env — falling back to Database for sessions/cache.");
            $cache = CacheDriver::DATABASE;
        }

        $values = $this->externalizedEnvValues($cache, $hasS3, $config);

        file_put_contents($envFile, $this->applyEnvValues((string) file_get_contents($envFile), $values));

        $this->laraKubeInfo("✅ Updated .env.{$env}:");
        foreach ($values as $key => $value) {
            $this->line("    <fg=green>{$key}</>={$value}");
        }
        if ($managed) {
            $this->newLine();
            $creds = 'AWS_* (S3/Spaces)'.($cache->commonsServiceName() !== null ? " and your {$cache->getLabel()} connection vars" : '');
            $this->laraKubeWarn("Managed backends — set the credentials yourself in .env.{$env}: {$creds}.");
        } elseif (! $hasS3) {
            $this->newLine();
            $this->laraKubeWarn('No object storage wired — left FILESYSTEM_DISK as-is. Point it at your S3/Spaces disk and set AWS_* yourself.');
        }
        $this->newLine();
        $this->line("  <fg=gray>Redeploy to apply:</> <fg=yellow>larakube cloud:deploy {$env}</>");

        return 0;
    }

    /**
     * The .env flips that externalize multi-node state. The CacheDriver enum owns
     * the session/cache/queue VALUES per store; we keep only the app-level selector
     * keys (never its connection vars) so a Commons/self-host connection survives.
     * Pure — the one tested source of truth for what we write.
     *
     * @return array<string, int|string>
     */
    public function externalizedEnvValues(CacheDriver $cache, bool $hasS3, ConfigData $config): array
    {
        $values = array_intersect_key(
            $cache->getPublicEnvironmentVariables($config),
            array_flip(self::DRIVER_SELECTOR_KEYS),
        );

        if ($hasS3) {
            // The Laravel disk selector — sourced from the project's storage enum
            // when one is configured (every S3 backend uses 's3'); same default for
            // managed/external S3.
            $values['FILESYSTEM_DISK'] = $config->getObjectStorage()
                ?->getPublicEnvironmentVariables($config)['FILESYSTEM_DISK'] ?? 's3';
        }

        return $values;
    }

    /**
     * Cache backends offerable for an env. Database is dropped when the primary DB
     * is SQLite — a file DB can't span nodes, so DB-backed sessions/cache would be
     * just as broken as `file`. Pure.
     *
     * @return array<int, CacheDriver>
     */
    public function offerableCacheDrivers(bool $dbIsExternal): array
    {
        return array_values(array_filter(
            CacheDriver::cases(),
            fn (CacheDriver $c) => $dbIsExternal || $c !== CacheDriver::DATABASE,
        ));
    }

    /**
     * What this env already has a working backend for — checked against its Plex
     * Commons membership AND the project's own declared services (a self-hosted
     * MinIO, or a declared Redis cache, works multi-node too). Database cache always
     * counts (it reuses the primary DB). So a driver is accepted because a backend
     * exists, never blindly.
     *
     * @return array{0: bool, 1: bool} [hasS3, cacheReady]
     */
    public function backendsPresent(ConfigData $config, string $env, CacheDriver $cache): array
    {
        $hasS3 = $this->plexS3Service($config, $env) !== null
            || $config->getObjectStorage() !== null;

        $service = $cache->commonsServiceName();   // null for Database
        $cacheReady = $service === null
            || in_array($service, $config->getPlex($env), true)
            || $config->getCacheDriver() === $cache;

        return [$hasS3, $cacheReady];
    }

    /** Pick the cache/session backend from the CacheDriver enum (project default pre-selected). */
    protected function chooseCacheDriver(ConfigData $config): CacheDriver
    {
        $dbIsExternal = $config->getDatabase()?->isExternal() ?? true;

        $options = [];
        foreach ($this->offerableCacheDrivers($dbIsExternal) as $case) {
            $options[$case->value] = $case->getLabel() ?? $case->value;
        }

        // Don't pre-select a Database cache we just excluded (it's the enum default).
        $current = $config->getCacheDriver()->value;
        $default = isset($options[$current]) ? $current : CacheDriver::REDIS->value;

        return CacheDriver::from(select(
            label: 'Sessions & cache should use…',
            options: $options,
            default: $default,
        ));
    }

    /**
     * The networked databases a SQLite project can switch to (enum-driven; SQLite
     * itself is excluded).
     *
     * @return array<string, string>
     */
    protected function networkedDatabaseOptions(): array
    {
        $options = [];
        foreach (DatabaseDriver::cases() as $db) {
            if (! $db->isExternal()) {
                continue;
            }
            $options[$db->value] = $db->getLabel() ?? $db->value;
        }

        return $options;
    }

    /**
     * Make the env's externalized backends real: object storage for uploads, and a
     * service for the chosen cache (unless it's Database). Returns the reloaded
     * config plus what's now available.
     *
     * @return array{0: ConfigData, 1: bool, 2: bool, 3: bool} [config, hasS3, cacheReady, managed]
     */
    protected function resolveBackends(ConfigData $config, string $env, CacheDriver $cache): array
    {
        [$hasS3, $cacheReady] = $this->backendsPresent($config, $env, $cache);

        // Show what's already wired (and where) so an accepted driver is visibly a
        // verified decision, not a silent one.
        $this->reportExistingBackends($config, $env, $cache, $hasS3, $cacheReady);

        if ($hasS3 && $cacheReady) {
            return [$config, true, true, false];
        }

        $missing = array_values(array_filter([
            ! $hasS3 ? 'an S3 bucket' : null,
            ! $cacheReady ? $cache->getLabel() : null,
        ]));
        $this->line('  This env needs <fg=cyan>'.implode(' and ', $missing).'</> to externalize cleanly. Where should it come from?');
        $this->newLine();

        // Only offer the shared Commons when one is actually initialized on this
        // env's cluster (plex:init was run) — otherwise plex:join can't work.
        $commonsExists = $this->commonsOffersOnContext($this->environmentContextOrCurrent($config, $env)) !== [];

        $options = [];
        if ($commonsExists) {
            $options['commons'] = 'Join the shared Commons already on this cluster';
        }
        $options['selfhost'] = 'Self-host them in this project (via `larakube add`)';
        $options['managed'] = "Managed / external (e.g. DO Spaces, Managed Redis) — I'll set the creds";

        $choice = select(
            label: 'Externalized backends',
            options: $options,
            default: $commonsExists ? 'commons' : 'selfhost',
        );

        if ($choice === 'commons') {
            $this->call('plex:join', ['environment' => $env]);
            $config = $this->getProjectConfig(getcwd());
            [$hasS3, $cacheReady] = $this->backendsPresent($config, $env, $cache);

            return [$config, $hasS3, $cacheReady, false];
        }

        if ($choice === 'selfhost') {
            // Reuse `larakube add` — StorageDriver/CacheDriver own image/port/env/
            // manifests, so backend wiring is never recoded here.
            $items = [];
            if (! $hasS3) {
                $items[] = select(
                    label: 'Which object storage should this project self-host?',
                    options: [
                        StorageDriver::MINIO->value => StorageDriver::MINIO->getLabel(),
                        StorageDriver::SEAWEEDFS->value => StorageDriver::SEAWEEDFS->getLabel(),
                        StorageDriver::GARAGE->value => StorageDriver::GARAGE->getLabel(),
                    ],
                    default: StorageDriver::MINIO->value,
                );
            }
            if (! $cacheReady && $cache->commonsServiceName() !== null) {
                $items[] = $cache->value;  // redis / memcached service
            }
            if ($items !== []) {
                $this->call('feature:add', ['items' => $items]);
                $config = $this->getProjectConfig(getcwd());
            }

            return [$config, true, true, false];
        }

        // Managed: they point creds at their own Spaces/Redis. Flip drivers + remind.
        return [$config, true, true, true];
    }

    /** The S3 service this env shares on its Plex Commons (e.g. 'minio'), or null. */
    protected function plexS3Service(ConfigData $config, string $env): ?string
    {
        $s3Services = array_map(fn (StorageDriver $d) => $d->value, StorageDriver::cases());
        $found = array_values(array_intersect($config->getPlex($env), $s3Services));

        return $found[0] ?? null;
    }

    /**
     * Print the backends already wired for this env and where each comes from, so
     * the user can see the command verified (Plex membership / declared services)
     * rather than silently accepting their choice.
     */
    protected function reportExistingBackends(ConfigData $config, string $env, CacheDriver $cache, bool $hasS3, bool $cacheReady): void
    {
        $lines = [];
        if ($hasS3) {
            $lines[] = "uploads (FILESYSTEM_DISK=s3) → {$this->s3Source($config, $env)}";
        }
        if ($cacheReady) {
            $lines[] = "sessions & cache ({$cache->value}) → {$this->cacheSource($config, $env, $cache)}";
        }
        if ($lines === []) {
            return;
        }

        $this->line("  <fg=green>Already wired for '{$env}'</> — pointing the app at it:");
        foreach ($lines as $line) {
            $this->line("    • {$line}");
        }
        $this->newLine();
    }

    /** Human label for where this env's object storage comes from. */
    protected function s3Source(ConfigData $config, string $env): string
    {
        if (($svc = $this->plexS3Service($config, $env)) !== null) {
            return "Plex Commons ({$svc})";
        }
        if (($storage = $config->getObjectStorage()) !== null) {
            return "self-hosted ({$storage->value})";
        }

        return 'managed / external';
    }

    /** Human label for where this env's chosen cache backend comes from. */
    protected function cacheSource(ConfigData $config, string $env, CacheDriver $cache): string
    {
        if ($cache === CacheDriver::DATABASE) {
            return 'your '.($config->getDatabase()?->getLabel() ?? 'primary').' database';
        }
        $service = $cache->commonsServiceName();
        if ($service !== null && in_array($service, $config->getPlex($env), true)) {
            return 'Plex Commons';
        }
        if ($config->getCacheDriver() === $cache) {
            return 'self-hosted in this project';
        }

        return 'managed / external';
    }

    /**
     * The Commons services initialized on this env's cluster — empty when no
     * Commons exists there (plex:init hasn't been run) or the cluster is
     * unreachable. Lets us avoid offering a plex:join that can't possibly work.
     *
     * @return array<int, string>
     */
    protected function commonsOffersOnContext(?string $context): array
    {
        if ($context === null || $context === '') {
            return [];
        }

        $this->plexContext = $context;
        if (! $this->plexContextReachable()) {
            return [];
        }

        $spec = $this->getCommonsSpec();

        return $spec === null ? [] : $this->enabledCommonsServices($spec);
    }
}
