<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;

use function Laravel\Prompts\select;

/**
 * Everything that makes a Next.js project deployable, shared by `nextjs:new`
 * (on a freshly scaffolded app) and `init` (on an existing one): the driver
 * wizard, standalone output, the Redis cache handler, Prisma, the health route
 * and the connection env. Hosts need ScaffoldsInNode and a NODE_IMAGE constant.
 */
trait PreparesNextjsProject
{
    /**
     * @return array{database: DatabaseDriver, cache: CacheDriver, storage: ?StorageDriver, search: ?SearchDriver}
     */
    protected function gatherNextjsDrivers(bool $fast): array
    {
        // 1. DatabaseDriver — MySQL, MariaDB, PostgreSQL via Prisma (plan §2a)
        $allowedDbs = [
            DatabaseDriver::POSTGRESQL->value => DatabaseDriver::POSTGRESQL->getLabel().' (Recommended)',
            DatabaseDriver::MYSQL->value => DatabaseDriver::MYSQL->getLabel(),
            DatabaseDriver::MARIADB->value => DatabaseDriver::MARIADB->getLabel(),
        ];

        $dbValue = $fast
            ? DatabaseDriver::POSTGRESQL->value
            : select(
                label: 'Which database engine would you like to use? (via Prisma)',
                options: $allowedDbs,
                default: DatabaseDriver::POSTGRESQL->value,
            );
        $database = DatabaseDriver::from($dbValue);

        // 2. CacheDriver — Redis ONLY (mandatory for distributed ISR/RSC, plan §2b)
        $this->laraKubeInfo('Cache: Redis is mandatory for distributed ISR/RSC caching across pods (@fortedigital/nextjs-cache-handler).');
        $cacheDriver = CacheDriver::REDIS;

        // 3. StorageDriver — S3-compatible pre-signed URL uploads (plan §2d)
        $allowedStorages = [
            'none' => 'None',
            StorageDriver::MINIO->value => StorageDriver::MINIO->getLabel().' (Recommended)',
            StorageDriver::SEAWEEDFS->value => StorageDriver::SEAWEEDFS->getLabel(),
            StorageDriver::GARAGE->value => StorageDriver::GARAGE->getLabel(),
        ];

        $storageValue = $fast
            ? StorageDriver::MINIO->value
            : select(
                label: 'Which S3-compatible object storage would you like to use?',
                options: $allowedStorages,
                default: StorageDriver::MINIO->value,
            );
        $objectStorage = StorageDriver::tryFrom($storageValue);

        // 4. SearchDriver — Meilisearch or Typesense (plan §2c; database hidden)
        $allowedSearch = [
            'none' => 'None',
            SearchDriver::MEILISEARCH->value => SearchDriver::MEILISEARCH->getLabel().' (meilisearch-js)',
            SearchDriver::TYPESENSE->value => SearchDriver::TYPESENSE->getLabel().' (typesense-js)',
        ];

        $searchValue = $fast
            ? 'none'
            : select(
                label: 'Which search engine would you like to use?',
                options: $allowedSearch,
                default: 'none',
            );
        $scoutDriver = SearchDriver::tryFrom($searchValue);

        return [
            'database' => $database,
            'cache' => $cacheDriver,
            'storage' => $objectStorage,
            'search' => $scoutDriver,
        ];
    }

    /**
     * Patch next.config.ts to set output: 'standalone' and disable in-memory cache.
     */
    protected function patchNextConfig(string $projectDir): void
    {
        // Try next.config.ts first, then .mjs, then .js
        $candidates = [
            "$projectDir/next.config.ts",
            "$projectDir/next.config.mjs",
            "$projectDir/next.config.js",
        ];

        $configFile = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $configFile = $candidate;
                break;
            }
        }

        if ($configFile === null) {
            // Create a minimal next.config.ts
            $configFile = "$projectDir/next.config.ts";
            file_put_contents($configFile, <<<'TS'
import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  output: 'standalone',
  cacheMaxMemorySize: 0,
  // Distributed ISR/RSC cache via cache-handler.mjs — production only; dev keeps
  // Next's own in-memory cache. require.resolve is available in next.config.
  cacheHandler:
    process.env.NODE_ENV === 'production'
      ? require.resolve('./cache-handler.mjs')
      : undefined,
};

export default nextConfig;
TS);
            $this->laraKubeInfo('Created next.config.ts with standalone output + Redis cache handler.');

            return;
        }

        $content = file_get_contents($configFile);

        // Inject output: 'standalone' + the cache handler if not already present.
        if (! str_contains($content, "'standalone'") && ! str_contains($content, '"standalone"')) {
            $inject = <<<'TS'
$1
  output: 'standalone',
  cacheMaxMemorySize: 0,
  cacheHandler:
    process.env.NODE_ENV === 'production'
      ? require.resolve('./cache-handler.mjs')
      : undefined,
TS;
            $content = preg_replace('/(const\s+nextConfig[^=]*=\s*\{)/', $inject, $content, 1);
            file_put_contents($configFile, $content);
            $this->laraKubeInfo('Patched next.config: standalone output + Redis cache handler.');
        }
    }

    /**
     * Generate cache-handler.mjs using @fortedigital/nextjs-cache-handler.
     *
     * The maintained, Next.js 15/16-compatible successor to @neshca/cache-handler
     * (which caps at Next <15). Wired into next.config only in production; Redis
     * being unreachable degrades to Next's own cache rather than failing boot.
     */
    protected function generateCacheHandler(string $projectDir): void
    {
        $content = <<<'JS'
import { CacheHandler } from '@fortedigital/nextjs-cache-handler';
import createRedisHandler from '@fortedigital/nextjs-cache-handler/redis-strings';
import { createClient } from 'redis';

CacheHandler.onCreation(async () => {
  let redisHandler;

  try {
    const client = createClient({ url: process.env.REDIS_URL ?? 'redis://localhost:6379' });
    client.on('error', (err) => console.error('[cache-handler] Redis client error', err));
    await client.connect();
    redisHandler = createRedisHandler({ client });
  } catch (err) {
    // Redis down: fall back to Next's in-memory cache rather than failing the
    // build or boot. Distributed ISR/RSC resumes once Redis is reachable.
    console.error('[cache-handler] Redis unavailable; using the default cache.', err);
  }

  return { handlers: redisHandler ? [redisHandler] : [] };
});

export default CacheHandler;
JS;

        file_put_contents("$projectDir/cache-handler.mjs", $content);
        $this->laraKubeInfo('Generated cache-handler.mjs for distributed Redis ISR caching.');
    }

    /**
     * Install the cache-handler's runtime deps into the scaffolded project.
     *
     * create-next-app ships neither @fortedigital/nextjs-cache-handler nor its
     * `redis` peer, so without this the standalone build fails to resolve the
     * cache-handler.mjs import. `npm install` (not a hand-edited package.json)
     * keeps package.json and the lockfile in sync so the Dockerfile's `npm ci`
     * and the dev pod's install both pick them up.
     */
    protected function installCacheDependencies(string $projectDir): void
    {
        $this->laraKubeInfo('Installing cache-handler dependencies (@fortedigital/nextjs-cache-handler, redis)...');
        $this->runInNodeContainer($projectDir, 'npm install @fortedigital/nextjs-cache-handler redis');
    }

    /**
     * Scaffold Prisma into the project: install the ORM + CLI and `prisma init`
     * with the datasource provider for the chosen engine (MySQL and MariaDB both
     * map to Prisma's `mysql` provider). `prisma init` also seeds a DATABASE_URL
     * placeholder in .env, which the Plex / --no-plex wiring overrides with the
     * real connection string.
     */
    protected function scaffoldPrisma(string $projectDir, DatabaseDriver $database): void
    {
        $provider = $database === DatabaseDriver::POSTGRESQL ? 'postgresql' : 'mysql';

        // Pinned to Prisma 6: `latest` currently serves the 8.0 release candidate,
        // whose `prisma init` is redesigned (no --datasource-provider, no schema
        // scaffold). v6 is the current stable with the classic init workflow.
        // Revisit when 8.x ships stable and the CLI settles.
        $this->laraKubeInfo("Scaffolding Prisma ($provider datasource)...");
        $this->runInNodeContainer(
            $projectDir,
            "npm install prisma@6 @prisma/client@6 && npx --yes prisma@6 init --datasource-provider $provider",
        );
    }

    /**
     * Point DATABASE_URL (Prisma) and REDIS_URL (cache handler) at the self-hosted
     * pods this pipeline renders. A Commons join replaces what it joined.
     */
    protected function wireDatabaseEnv(ConfigData $config, string $projectDir, DatabaseDriver $database): void
    {
        $scheme = $database === DatabaseDriver::POSTGRESQL ? 'postgresql' : 'mysql';
        $dbName = $config->getName();
        $dbHost = $config->getName().'-'.$database->value;
        $port = $database->dbPort();
        $password = bin2hex(random_bytes(16));

        $this->syncEnvFile($projectDir, [
            'DATABASE_URL' => "{$scheme}://{$dbName}:{$password}@{$dbHost}:{$port}/{$dbName}",
            'REDIS_URL' => 'redis://redis:6379',
            // Discrete values the self-hosted DB pod's container reads.
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbName,
            'DB_PASSWORD' => $password,
        ], false, 'local');
    }

    /**
     * Rebuild DATABASE_URL/REDIS_URL from the values plex:join wrote, for only the
     * services the blueprint says actually joined. Returns what it wrote.
     *
     * @return array<string, string>
     */
    protected function wireCommonsDatabaseEnv(string $projectDir, DatabaseDriver $database): array
    {
        $plex = $this->getProjectConfig($projectDir)?->getPlex('local') ?? [];
        $dbJoined = in_array($database->commonsServiceName(), $plex, true);
        $redisJoined = in_array('redis', $plex, true);

        if (! $dbJoined && ! $redisJoined) {
            return [];
        }

        $env = $this->readDotEnv($projectDir.'/.env');
        $values = [];

        if ($dbJoined) {
            $missing = array_values(array_filter(
                ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
                fn (string $key): bool => ($env[$key] ?? '') === '',
            ));

            if ($missing === []) {
                $scheme = $database === DatabaseDriver::POSTGRESQL ? 'postgresql' : 'mysql';
                $values['DATABASE_URL'] = sprintf(
                    '%s://%s:%s@%s:%s/%s',
                    $scheme,
                    rawurlencode($env['DB_USERNAME']),
                    rawurlencode($env['DB_PASSWORD']),
                    $env['DB_HOST'],
                    $env['DB_PORT'],
                    $env['DB_DATABASE'],
                );
            } else {
                $this->laraKubeWarn('DATABASE_URL still points at the self-hosted database: .env is missing '.implode(', ', $missing).'.');
                $this->laraKubeLine('  <fg=gray>Set</> <fg=cyan>DATABASE_URL</> <fg=gray>in .env to the Commons database before running</> <fg=cyan>larakube up</><fg=gray>.</>');
            }
        }

        if ($redisJoined && ($env['REDIS_HOST'] ?? '') !== '') {
            $values['REDIS_URL'] = sprintf('redis://%s:%s/%s', $env['REDIS_HOST'], $env['REDIS_PORT'] ?? '6379', $env['REDIS_DB'] ?? '0');
        }

        if ($values === []) {
            return [];
        }

        $this->syncEnvFile($projectDir, $values, false, 'local');

        // The generated Secret carries these, so render it again with the final values.
        $previous = getcwd();
        chdir($projectDir);

        try {
            if ($this->callSilent('heal', ['--force' => true]) !== 0) {
                $this->laraKubeWarn('Could not regenerate manifests. Run `larakube heal --force` before `larakube up`.');
            }
        } finally {
            if ($previous !== false) {
                chdir($previous);
            }
        }

        return $values;
    }

    /**
     * Run a command in the Node builder image against the project dir, under the
     * active runtime, reusing the shared npm cache volume and handing the tree
     * back to the host user afterward (0:0 under rootless Podman — see
     * containerChownSpec).
     */
    protected function runInNodeContainer(string $projectDir, string $command): void
    {
        $runtime = $this->containerRuntime();
        $dir = escapeshellarg($projectDir);
        $cache = '-v '.self::NPM_CACHE_VOLUME.':/npm-cache -e npm_config_cache=/npm-cache';

        $this->runStreaming(
            "$runtime run --rm -v $dir:/app -w /app $cache --user root ".self::NODE_IMAGE.' sh -c '.escapeshellarg($command),
        );

        $this->runStreaming(
            "$runtime run --rm -v $dir:/app --user root ".self::NODE_IMAGE
            .' chown -R '.$this->containerChownSpec($this->hostUid(), $this->hostGid()).' /app',
        );
    }

    /**
     * Generate a /api/health route handler.
     */
    protected function generateHealthRoute(string $projectDir): void
    {
        $routeDir = $this->nextjsHealthRoutePath($projectDir);
        if (! is_dir($routeDir)) {
            mkdir($routeDir, 0o755, true);
        }

        $content = <<<'TS'
import { NextResponse } from 'next/server';

export async function GET() {
  return NextResponse.json({ status: 'ok', timestamp: new Date().toISOString() });
}
TS;

        file_put_contents("$routeDir/route.ts", $content);
        $this->laraKubeInfo('Generated health check route at '.substr($routeDir, strlen($projectDir) + 1).'/route.ts.');
    }

    /** Where the health route lives: under src/app for a src-directory project. */
    protected function nextjsHealthRoutePath(string $projectDir): string
    {
        $appDir = is_dir("{$projectDir}/src/app") && ! is_dir("{$projectDir}/app") ? 'src/app' : 'app';

        return "{$projectDir}/{$appDir}/api/health";
    }

    /**
     * What `init` would add to an existing Next.js project, each step only
     * when the project doesn't already have it.
     *
     * @return list<string>
     */
    protected function plannedNextjsChanges(string $projectDir): array
    {
        $env = $this->readDotEnv("{$projectDir}/.env");
        $healthRoute = $this->nextjsHealthRoutePath($projectDir).'/route.ts';

        return array_values(array_filter([
            $this->nextjsHasStandaloneOutput($projectDir) ? null : "next.config: output: 'standalone' + Redis cache handler",
            file_exists("{$projectDir}/cache-handler.mjs") ? null : 'cache-handler.mjs + npm install @fortedigital/nextjs-cache-handler redis',
            file_exists("{$projectDir}/prisma/schema.prisma") ? null : 'Prisma (npm install prisma@6 @prisma/client@6, prisma init)',
            file_exists($healthRoute) ? null : substr($healthRoute, strlen($projectDir) + 1).' (the readiness/liveness probe target)',
            ($env['DATABASE_URL'] ?? '') !== '' ? null : '.env: DATABASE_URL, REDIS_URL and DB_* for the self-hosted database',
        ]));
    }

    /** Make an existing Next.js project deployable, skipping whatever it already has. */
    protected function prepareExistingNextjsProject(ConfigData $config, string $projectDir, DatabaseDriver $database): void
    {
        if (! $this->nextjsHasStandaloneOutput($projectDir)) {
            $this->patchNextConfig($projectDir);
        }

        if (! file_exists("{$projectDir}/cache-handler.mjs")) {
            $this->generateCacheHandler($projectDir);
            $this->installCacheDependencies($projectDir);
        }

        if (! file_exists("{$projectDir}/prisma/schema.prisma")) {
            $this->scaffoldPrisma($projectDir, $database);
        }

        if (! file_exists($this->nextjsHealthRoutePath($projectDir).'/route.ts')) {
            $this->generateHealthRoute($projectDir);
        }

        if (($this->readDotEnv("{$projectDir}/.env")['DATABASE_URL'] ?? '') === '') {
            $this->wireDatabaseEnv($config, $projectDir, $database);
        }
    }

    protected function nextjsHasStandaloneOutput(string $projectDir): bool
    {
        foreach (['next.config.ts', 'next.config.mjs', 'next.config.js'] as $file) {
            $content = @file_get_contents("{$projectDir}/{$file}");
            if ($content !== false && (str_contains($content, "'standalone'") || str_contains($content, '"standalone"'))) {
                return true;
            }
        }

        return false;
    }
}
