<?php

namespace App\Commands\Nextjs;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ScaffoldsInNode;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class NextjsNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ScaffoldsInNode, StreamsProcessOutput, SyncsClusterSecrets;

    /** The Node builder image the scaffolder runs in (matches the sibling frontends). */
    protected const NODE_IMAGE = 'node:24-alpine';

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'nextjs:new
                            {name? : The name of the Next.js application}
                            {--fast : Skip wizard and use ideal defaults}
                            {--no-plex : Skip Plex Commons auto-provisioning and use self-hosted database/redis}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new Next.js application with Kubernetes infrastructure (standalone output + Redis cache handler)';

    /**
     * Backward-compatible alias for those who prefer the shorthand.
     *
     * @var array<int, string>
     */
    protected $aliases = ['next:new'];

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();

        // Provisioning happens against the local cluster during scaffold, so the
        // Plex helpers use the current kube-context (see PlexContextWiringTest).
        $this->plexContext = null;

        $projectPath = getcwd();

        if (! $this->checkPrerequisites(false)) {
            return 1;
        }

        $inputName = $this->argument('name') ?? text(
            label: 'What is the name of your Next.js application?',
            placeholder: 'my-nextjs-app',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        // 1. DatabaseDriver — MySQL, MariaDB, PostgreSQL via Prisma (plan §2a)
        $allowedDbs = [
            DatabaseDriver::POSTGRESQL->value => DatabaseDriver::POSTGRESQL->getLabel().' (Recommended)',
            DatabaseDriver::MYSQL->value => DatabaseDriver::MYSQL->getLabel(),
            DatabaseDriver::MARIADB->value => DatabaseDriver::MARIADB->getLabel(),
        ];

        $dbValue = $this->option('fast')
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

        $storageValue = $this->option('fast')
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

        $searchValue = $this->option('fast')
            ? 'none'
            : select(
                label: 'Which search engine would you like to use?',
                options: $allowedSearch,
                default: 'none',
            );
        $scoutDriver = SearchDriver::tryFrom($searchValue);

        // Build ConfigData
        $config = new ConfigData;
        $config->setIsScaffolding(true);
        $config->setName($appName);
        $config->setPath($projectDir);
        $config->setEnvironments(['local']);
        $config->framework = AppFramework::NEXTJS;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding Next.js: $appName...");

        // 5. Run create-next-app inside Node (runtime-agnostic: Docker or Podman)
        if (! $this->runCreateNextApp($appName, $projectPath)) {
            $this->laraKubeError('Failed to create Next.js application.');

            return 1;
        }

        // 6. Patch next.config.ts for standalone output
        $this->patchNextConfig($projectDir);

        // 7. Generate Redis cache-handler + install its deps (create-next-app
        //    ships neither the handler package nor its redis peer).
        $this->generateCacheHandler($projectDir);
        $this->installCacheDependencies($projectDir);

        // 7b. Scaffold Prisma for the chosen database engine.
        $this->scaffoldPrisma($projectDir, $database);

        // 8. Generate health check route
        $this->generateHealthRoute($projectDir);

        // 8b. Provision the database + Redis (Plex Commons by default, self-hosted
        //     with --no-plex), then wire DATABASE_URL/REDIS_URL into .env so Prisma
        //     and the cache handler resolve them and the synced Secret carries them.
        $plexCredentials = $this->option('no-plex') ? null : $this->ensurePlexProvisionedForApp($config);
        if ($plexCredentials !== null) {
            $config->addEnvironment('local');
            $config->environments['local']->plex = array_values(array_unique(
                array_merge($config->environments['local']->plex, $plexCredentials['services'] ?? []),
            ));
        }
        $this->wireDatabaseEnv($config, $projectDir, $database, $plexCredentials);

        // 9. Generate K8s manifests
        $this->withSpin('Orchestrating Next.js infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config);
        });

        $this->laraKubeInfo("✅ Next.js project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your Next.js application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Key configuration applied:</>');
        $this->line("  <fg=gray>  • output: 'standalone' — patched in next.config.ts</>");
        $this->line('  <fg=gray>  • Redis cache handler — cache-handler.mjs via @fortedigital/nextjs-cache-handler</>');
        $this->line('  <fg=gray>  • Health check route — app/api/health/route.ts</>');
        $this->line('  <fg=gray>  • Prisma migrations — run via K8s init container</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Scaffold the Next.js app inside Node via the shared ScaffoldsInNode trait,
     * so it runs under whichever container runtime is active (Docker or Podman)
     * and reuses the trait's TTY handling, host-user chown, and directory check.
     *
     * Interactive, create-next-app runs its own wizard — the same handoff
     * `larakube new` makes to `laravel new`. Only the choices the follow-up steps
     * depend on are pinned: the App Router and TypeScript at the project root, so
     * standalone patching and app/api/health/route.ts land where they're expected,
     * plus --no-git to avoid a nested repo. Tailwind, ESLint, the import alias and
     * Turbopack are the wizard's to ask. Scripted (no TTY, --fast,
     * --no-interaction) every option must be answered up front, so that line takes
     * the opinionated LaraKube defaults.
     */
    protected function runCreateNextApp(string $appName, string $baseDir): bool
    {
        $interactive = "npx --yes create-next-app@latest {$appName} --ts --app --no-src-dir --no-git";

        $scripted = "npx --yes create-next-app@latest {$appName}"
            .' --ts --tailwind --eslint --app --no-src-dir --import-alias "@/*" --no-git';

        return $this->scaffoldInNode($appName, $baseDir, 'Next.js app', $interactive, $scripted);
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
     * Build DATABASE_URL (Prisma) and REDIS_URL (cache handler) and write them to
     * .env. Plex points at the Commons engines with the tenant's credentials
     * (the password is in the provisioning result here at scaffold time); the
     * self-hosted --no-plex path points at the pods this pipeline renders, with a
     * fresh password shared with the DB pod via DB_PASSWORD.
     */
    protected function wireDatabaseEnv(ConfigData $config, string $projectDir, DatabaseDriver $database, ?array $plexCredentials): void
    {
        $scheme = $database === DatabaseDriver::POSTGRESQL ? 'postgresql' : 'mysql';

        if ($plexCredentials !== null) {
            $tenant = (string) $plexCredentials['tenant'];
            $password = (string) $plexCredentials['password'];
            $host = (string) $plexCredentials['host'];
            $port = (int) $plexCredentials['port'];

            $redisIndex = $this->getRegistry()['tenants'][$tenant]['redis_index'] ?? 0;
            $redisUrl = 'redis://redis.'.$this->plexNamespace().".svc.cluster.local:6379/{$redisIndex}";

            $this->syncEnvFile($projectDir, [
                'DATABASE_URL' => "{$scheme}://{$tenant}:{$password}@{$host}:{$port}/{$tenant}",
                'REDIS_URL' => $redisUrl,
            ], false, 'local');

            return;
        }

        // Self-hosted: a per-app DB pod (rendered on --no-plex) + the Redis pod.
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
        $routeDir = "$projectDir/app/api/health";
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
        $this->laraKubeInfo('Generated health check route at app/api/health/route.ts.');
    }
}
