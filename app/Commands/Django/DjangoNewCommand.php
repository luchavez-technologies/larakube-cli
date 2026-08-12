<?php

namespace App\Commands\Django;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithArchitecturalEngine;
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

class DjangoNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'django:new
                            {name? : The name of the Django application}
                            {--fast : Skip wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new Django application with Kubernetes infrastructure';

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
            label: 'What is the name of your Django application?',
            placeholder: 'my-django-app',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        // 1. DatabaseDriver — PostgreSQL (recommended), MySQL, MariaDB
        $allowedDbs = [
            DatabaseDriver::POSTGRESQL->value => DatabaseDriver::POSTGRESQL->getLabel().' (Recommended)',
            DatabaseDriver::MYSQL->value => DatabaseDriver::MYSQL->getLabel(),
            DatabaseDriver::MARIADB->value => DatabaseDriver::MARIADB->getLabel(),
        ];

        $dbValue = $this->option('fast')
            ? DatabaseDriver::POSTGRESQL->value
            : select(
                label: 'Which database engine would you like to use?',
                options: $allowedDbs,
                default: DatabaseDriver::POSTGRESQL->value,
            );
        $database = DatabaseDriver::from($dbValue);

        // 2. CacheDriver — Redis (recommended), Memcached, Database
        $allowedCaches = [
            CacheDriver::REDIS->value => CacheDriver::REDIS->getLabel().' (Recommended)',
            CacheDriver::MEMCACHED->value => CacheDriver::MEMCACHED->getLabel(),
            CacheDriver::DATABASE->value => CacheDriver::DATABASE->getLabel(),
        ];

        $cacheValue = $this->option('fast')
            ? CacheDriver::REDIS->value
            : select(
                label: 'Which cache driver would you like to use?',
                options: $allowedCaches,
                default: CacheDriver::REDIS->value,
            );
        $cacheDriver = CacheDriver::from($cacheValue);

        // 3. StorageDriver — S3-compatible object storage via django-storages
        $allowedStorages = [
            'none' => 'None (local filesystem)',
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
            SearchDriver::MEILISEARCH->value => SearchDriver::MEILISEARCH->getLabel(),
            SearchDriver::TYPESENSE->value => SearchDriver::TYPESENSE->getLabel(),
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
        $config->framework = AppFramework::DJANGO;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding Django: $appName...");

        // 5. Run django-admin startproject inside a Python 3.12 Docker container
        $this->runDjangoStartProject($appName, $projectPath);

        if (! is_dir($projectDir)) {
            $this->laraKubeError('Failed to create Django application.');

            return 1;
        }

        // 6. Generate Dockerfile and requirement configs
        $this->generateDjangoRequirements($projectDir, $database, $cacheDriver, $objectStorage);

        // 7. Generate K8s manifests
        $this->withSpin('Orchestrating Django infrastructure manifests...', function () use ($config) {
            $this->orchestrateProjectScaffolding($config);
        });

        $this->laraKubeInfo("✅ Django project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your Django application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Features configured:</>');
        $this->line('  <fg=gray>  • Gunicorn + Uvicorn worker runner</>');
        $this->line('  <fg=gray>  • Automatic K8s database migrations (python manage.py migrate)</>');
        $this->line('  <fg=gray>  • Health check endpoint at /healthz</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Run `django-admin startproject` inside a Python 3.12-slim Docker container.
     */
    protected function runDjangoStartProject(string $appName, string $baseDir): void
    {
        $this->laraKubeInfo('Pulling Python 3.12 slim builder image...');
        Process::forever()->run('docker pull python:3.12-slim');

        $uid = $this->hostUid();
        $gid = $this->hostGid();

        $cmd = "docker run --rm -it -v $baseDir:/app -w /app --user root python:3.12-slim"
            ." sh -c 'pip install --no-cache-dir django && django-admin startproject $appName .'";

        // If directory doesn't exist, create it and run inside
        if (! is_dir("$baseDir/$appName")) {
            mkdir("$baseDir/$appName", 0o755, true);
        }

        $cmd = "docker run --rm -it -v $baseDir/$appName:/app -w /app --user root python:3.12-slim"
            ." sh -c 'pip install --no-cache-dir django && django-admin startproject config .'";

        passthru($cmd);

        // Chown back to host user
        if (is_dir("$baseDir/$appName")) {
            $this->runStreaming(
                "docker run --rm -v $baseDir:/app --user root python:3.12-slim chown -R $uid:$gid /app/$appName",
            );
        }
    }

    /**
     * Generate requirements.txt for Django with selected driver dependencies.
     */
    protected function generateDjangoRequirements(
        string $projectDir,
        DatabaseDriver $database,
        CacheDriver $cacheDriver,
        ?StorageDriver $objectStorage,
    ): void {
        $packages = [
            'Django>=5.0,<6.0',
            'gunicorn>=22.0.0',
            'uvicorn>=0.30.0',
            'python-dotenv>=1.0.0',
        ];

        // DB packages
        if ($database === DatabaseDriver::POSTGRESQL) {
            $packages[] = 'psycopg[binary]>=3.1.0';
        } elseif ($database === DatabaseDriver::MYSQL || $database === DatabaseDriver::MARIADB) {
            $packages[] = 'mysqlclient>=2.2.0';
        }

        // Cache packages
        if ($cacheDriver === CacheDriver::REDIS) {
            $packages[] = 'redis>=5.0.0';
        } elseif ($cacheDriver === CacheDriver::MEMCACHED) {
            $packages[] = 'pymemcache>=4.0.0';
        }

        // Storage packages
        if ($objectStorage !== null) {
            $packages[] = 'django-storages[s3]>=1.14.0';
            $packages[] = 'boto3>=1.34.0';
        }

        file_put_contents("$projectDir/requirements.txt", implode("\n", $packages)."\n");
        $this->laraKubeInfo('Generated requirements.txt with driver dependencies.');
    }
}
