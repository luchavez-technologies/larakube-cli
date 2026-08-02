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
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class NextjsNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'nextjs:new
                            {name? : The name of the Next.js application}
                            {--fast : Skip wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new Next.js application with Kubernetes infrastructure (standalone output + Redis cache handler)';

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();

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
        $this->laraKubeInfo('Cache: Redis is mandatory for distributed ISR/RSC caching across pods (@neshca/cache-handler).');
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

        // 5. Run create-next-app inside a Node.js Docker container
        $this->runCreateNextApp($appName, $projectPath);

        if (! is_dir($projectDir)) {
            $this->laraKubeError('Failed to create Next.js application.');

            return 1;
        }

        // 6. Patch next.config.ts for standalone output
        $this->patchNextConfig($projectDir);

        // 7. Generate Redis cache-handler
        $this->generateCacheHandler($projectDir);

        // 8. Generate health check route
        $this->generateHealthRoute($projectDir);

        // 9. Generate K8s manifests
        $this->withSpin('Orchestrating Next.js infrastructure manifests...', function () use ($config) {
            $this->orchestrateProjectScaffolding($config);
        });

        $this->laraKubeInfo("✅ Next.js project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your Next.js application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Key configuration applied:</>');
        $this->line("  <fg=gray>  • output: 'standalone' — patched in next.config.ts</>");
        $this->line('  <fg=gray>  • Redis cache handler — cache-handler.mjs via @neshca/cache-handler</>');
        $this->line('  <fg=gray>  • Health check route — app/api/health/route.ts</>');
        $this->line('  <fg=gray>  • Prisma migrations — run via K8s init container</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Run `npx create-next-app@latest` inside a Node.js 22-alpine Docker container.
     */
    protected function runCreateNextApp(string $appName, string $baseDir): void
    {
        $this->laraKubeInfo('Pulling Node.js 22 Alpine builder image...');
        Process::forever()->run('docker pull node:22-alpine');

        $uid = $this->hostUid();
        $gid = $this->hostGid();

        $cmd = "docker run --rm -it -v $baseDir:/app -w /app --user root node:22-alpine"
            ." sh -c 'npx --yes create-next-app@latest $appName"
            .' --ts --tailwind --eslint --app --no-src-dir --import-alias "@/*"'
            ." --no-git'";

        passthru($cmd);

        // Chown back to host user
        if (is_dir("$baseDir/$appName")) {
            $this->runStreaming(
                "docker run --rm -v $baseDir:/app --user root node:22-alpine chown -R $uid:$gid /app/$appName",
            );
        }
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
  experimental: {
    instrumentationHook: true,
  },
};

export default nextConfig;
TS);
            $this->laraKubeInfo('Created next.config.ts with standalone output mode.');

            return;
        }

        $content = file_get_contents($configFile);

        // Inject output: 'standalone' if not already present
        if (! str_contains($content, "'standalone'") && ! str_contains($content, '"standalone"')) {
            $content = preg_replace(
                '/(const\s+nextConfig[^=]*=\s*\{)/',
                '$1'."\n  output: 'standalone',\n  cacheMaxMemorySize: 0,",
                $content,
                1,
            );
            file_put_contents($configFile, $content);
            $this->laraKubeInfo('Patched next.config: output=standalone, cacheMaxMemorySize=0.');
        }
    }

    /**
     * Generate cache-handler.mjs using @neshca/cache-handler for Redis.
     */
    protected function generateCacheHandler(string $projectDir): void
    {
        $content = <<<'JS'
import { createClient } from 'redis';
import { CacheHandler } from '@neshca/cache-handler';
import createRedisHandler from '@neshca/cache-handler/redis-stack';
import createLocalHandler from '@neshca/cache-handler/local';

CacheHandler.onCreation(async () => {
  const redisUrl = process.env.REDIS_URL ?? 'redis://localhost:6379';

  let client;
  try {
    client = createClient({ url: redisUrl });
    client.on('error', (err) => {
      console.error('[cache-handler] Redis Client Error', err);
    });
    await client.connect();
  } catch (err) {
    console.error('[cache-handler] Failed to connect to Redis. Falling back to local cache.', err);
    return {
      handlers: [createLocalHandler()],
    };
  }

  const redisHandler = await createRedisHandler({ client });

  return {
    handlers: [redisHandler],
  };
});

export default CacheHandler;
JS;

        file_put_contents("$projectDir/cache-handler.mjs", $content);
        $this->laraKubeInfo('Generated cache-handler.mjs for distributed Redis ISR caching.');
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
