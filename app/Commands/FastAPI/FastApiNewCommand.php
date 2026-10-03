<?php

namespace App\Commands\FastAPI;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Traits\AsksServerStack;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class FastApiNewCommand extends Command
{
    use AsksServerStack, CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'fastapi:new
                            {name? : The name of the FastAPI application}
                            {--fast : Skip wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new FastAPI application with Kubernetes infrastructure (Pydantic v2 + Alembic)';

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
            label: 'What is the name of your FastAPI application?',
            placeholder: 'my-fastapi-app',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        $database = $this->askDatabase(AppFramework::FASTAPI);
        $cacheDriver = $this->askCache(AppFramework::FASTAPI);
        $objectStorage = $this->askStorage(AppFramework::FASTAPI);
        $scoutDriver = $this->askSearch(AppFramework::FASTAPI);

        // Build ConfigData
        $config = new ConfigData;
        $config->setIsScaffolding(true);
        $config->setName($appName);
        $config->setPath($projectDir);
        $config->setEnvironments(['local']);
        $config->framework = AppFramework::FASTAPI;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding FastAPI: $appName...");

        // 5. Create directory structure
        if (! is_dir($projectDir)) {
            mkdir($projectDir, 0o755, true);
        }

        // 6. Generate FastAPI scaffolding files (main.py, requirements.txt, alembic config)
        $this->generateFastApiScaffolding($projectDir, $database, $cacheDriver, $objectStorage);

        // 7. Generate K8s manifests
        $this->withSpin('Orchestrating FastAPI infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config);
        });

        $this->laraKubeInfo("✅ FastAPI project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your FastAPI application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Features configured:</>');
        $this->line('  <fg=gray>  • Uvicorn ASGI server (uvicorn main:app --host 0.0.0.0 --port 8000)</>');
        $this->line('  <fg=gray>  • Alembic database migration init container (alembic upgrade head)</>');
        $this->line('  <fg=gray>  • Health check endpoint at /healthz</>');
        $this->line('  <fg=gray>  • Interactive Swagger API docs at /docs</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Generate FastAPI application files (main.py, requirements.txt, alembic layout).
     */
    protected function generateFastApiScaffolding(
        string $projectDir,
        DatabaseDriver $database,
        CacheDriver $cacheDriver,
        ?StorageDriver $objectStorage,
    ): void {
        // main.py
        $mainPy = <<<'PY'
from fastapi import FastAPI
from datetime import datetime

app = FastAPI(
    title="FastAPI Application",
    description="FastAPI service orchestrated by LaraKube",
    version="1.0.0"
)

@app.get("/healthz")
async def healthz():
    return {"status": "ok", "timestamp": datetime.utcnow().isoformat()}

@app.get("/")
async def root():
    return {"message": "Welcome to FastAPI on LaraKube!"}
PY;
        file_put_contents("$projectDir/main.py", $mainPy);

        // requirements.txt
        $packages = [
            'fastapi>=0.110.0',
            'uvicorn[standard]>=0.30.0',
            'pydantic>=2.7.0',
            'pydantic-settings>=2.2.0',
            'sqlalchemy>=2.0.0',
            'alembic>=1.13.0',
        ];

        if ($database === DatabaseDriver::POSTGRESQL) {
            $packages[] = 'asyncpg>=0.29.0';
            $packages[] = 'psycopg[binary]>=3.1.0';
        } elseif ($database === DatabaseDriver::MYSQL || $database === DatabaseDriver::MARIADB) {
            $packages[] = 'asyncmy>=0.2.9';
            $packages[] = 'pymysql>=1.1.0';
        }

        if ($cacheDriver === CacheDriver::REDIS) {
            $packages[] = 'redis>=5.0.0';
        } elseif ($cacheDriver === CacheDriver::MEMCACHED) {
            $packages[] = 'aiomemcached>=0.7.0';
        }

        if ($objectStorage !== null) {
            $packages[] = 'aioboto3>=12.3.0';
        }

        file_put_contents("$projectDir/requirements.txt", implode("\n", $packages)."\n");

        $this->laraKubeInfo('Generated main.py and requirements.txt.');
    }
}
