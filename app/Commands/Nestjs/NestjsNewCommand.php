<?php

namespace App\Commands\Nestjs;

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
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class NestjsNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'nestjs:new
                            {name? : The name of the NestJS application}
                            {--fast : Skip wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new NestJS application with Kubernetes infrastructure (TypeScript + Prisma + Terminus)';

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
            label: 'What is the name of your NestJS application?',
            placeholder: 'my-nestjs-app',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        // 1. DatabaseDriver — PostgreSQL (recommended via Prisma), MySQL, MariaDB
        $allowedDbs = [
            DatabaseDriver::POSTGRESQL->value => DatabaseDriver::POSTGRESQL->getLabel().' (Recommended via Prisma)',
            DatabaseDriver::MYSQL->value => DatabaseDriver::MYSQL->getLabel(),
            DatabaseDriver::MARIADB->value => DatabaseDriver::MARIADB->getLabel(),
        ];

        $dbValue = $this->option('fast')
            ? DatabaseDriver::POSTGRESQL->value
            : select(
                label: 'Which database engine would you like to use? (Prisma ORM)',
                options: $allowedDbs,
                default: DatabaseDriver::POSTGRESQL->value,
            );
        $database = DatabaseDriver::from($dbValue);

        // 2. CacheDriver — Redis (recommended via cache-manager-ioredis)
        $allowedCaches = [
            CacheDriver::REDIS->value => CacheDriver::REDIS->getLabel().' (Recommended via cache-manager-ioredis)',
            CacheDriver::MEMCACHED->value => CacheDriver::MEMCACHED->getLabel(),
        ];

        $cacheValue = $this->option('fast')
            ? CacheDriver::REDIS->value
            : select(
                label: 'Which cache driver would you like to use?',
                options: $allowedCaches,
                default: CacheDriver::REDIS->value,
            );
        $cacheDriver = CacheDriver::from($cacheValue);

        // 3. StorageDriver — S3-compatible object storage via @aws-sdk/client-s3
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

        // 4. SearchDriver — Meilisearch or Typesense
        $allowedSearch = [
            'none' => 'None',
            SearchDriver::MEILISEARCH->value => SearchDriver::MEILISEARCH->getLabel().' (meilisearch-js)',
            SearchDriver::TYPESENSE->value => SearchDriver::TYPESENSE->getLabel().' (typesense-js)',
        ];

        $searchValue = $this->option('fast')
            ? 'none'
            : select(
                label: 'Which search driver would you like to use?',
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
        $config->framework = AppFramework::NESTJS;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding NestJS: $appName...");

        // 5. Run npx @nestjs/cli new inside a Node.js 22-alpine Docker container
        $this->runNestCliNew($appName, $projectPath);

        if (! is_dir($projectDir)) {
            $this->laraKubeError('Failed to create NestJS application.');

            return 1;
        }

        // 6. Generate K8s manifests
        $this->withSpin('Orchestrating NestJS infrastructure manifests...', function () use ($config) {
            $this->orchestrateProjectScaffolding($config);
        });

        $this->laraKubeInfo("✅ NestJS project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your NestJS application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Features configured:</>');
        $this->line('  <fg=gray>  • NestJS TypeScript modular architecture (Node.js 22 Alpine)</>');
        $this->line('  <fg=gray>  • Prisma ORM database migration init container (prisma migrate deploy)</>');
        $this->line('  <fg=gray>  • Health check endpoint at /healthz</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Run `npx @nestjs/cli new` inside a Node.js 22-alpine Docker container.
     */
    protected function runNestCliNew(string $appName, string $baseDir): void
    {
        $this->laraKubeInfo('Pulling Node.js 22 Alpine builder image...');
        Process::forever()->run('docker pull node:22-alpine');

        $uid = $this->hostUid();
        $gid = $this->hostGid();

        $cmd = "docker run --rm -it -v $baseDir:/app -w /app --user root node:22-alpine"
            ." sh -c 'npx --yes @nestjs/cli new $appName --package-manager npm --strict --no-git'";

        passthru($cmd);

        // Chown back to host user
        if (is_dir("$baseDir/$appName")) {
            $this->runStreaming(
                "docker run --rm -v $baseDir:/app --user root node:22-alpine chown -R $uid:$gid /app/$appName",
            );
        }
    }
}
