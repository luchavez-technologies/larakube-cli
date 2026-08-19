<?php

namespace App\Commands\Dotnet;

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

class DotnetNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'dotnet:new
                            {name? : The name of the .NET Core application}
                            {--fast : Skip wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new ASP.NET Core 9.0 Web API application with Kubernetes infrastructure';

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
            label: 'What is the name of your .NET Core application?',
            placeholder: 'my-dotnet-app',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        // 1. DatabaseDriver — PostgreSQL (recommended via Npgsql), MySQL, MariaDB
        $allowedDbs = [
            DatabaseDriver::POSTGRESQL->value => DatabaseDriver::POSTGRESQL->getLabel().' (Recommended via Npgsql)',
            DatabaseDriver::MYSQL->value => DatabaseDriver::MYSQL->getLabel().' (Pomelo EF Core)',
            DatabaseDriver::MARIADB->value => DatabaseDriver::MARIADB->getLabel(),
        ];

        $dbValue = $this->option('fast')
            ? DatabaseDriver::POSTGRESQL->value
            : select(
                label: 'Which database engine would you like to use? (Entity Framework Core)',
                options: $allowedDbs,
                default: DatabaseDriver::POSTGRESQL->value,
            );
        $database = DatabaseDriver::from($dbValue);

        // 2. CacheDriver — Redis (recommended)
        $allowedCaches = [
            CacheDriver::REDIS->value => CacheDriver::REDIS->getLabel().' (Recommended)',
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

        // 3. StorageDriver — S3-compatible object storage via AWSSDK.S3
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
        $config->framework = AppFramework::DOTNET;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding ASP.NET Core 9.0 Web API: $appName...");

        // 5. Run `dotnet new webapi` inside a .NET 9 SDK Docker container
        $this->runDotnetNewWebapi($appName, $projectPath);

        if (! is_dir($projectDir)) {
            $this->laraKubeError('Failed to create .NET Core application.');

            return 1;
        }

        // 6. Generate/Patch Program.cs for health checks
        $this->generateDotnetProgramCs($projectDir);

        // 7. Generate K8s manifests
        $this->withSpin('Orchestrating .NET Core infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config);
        });

        $this->laraKubeInfo("✅ .NET Core project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your .NET Core application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Features configured:</>');
        $this->line('  <fg=gray>  • ASP.NET Core 9.0 Alpine runner (mcr.microsoft.com/dotnet/aspnet:9.0-alpine)</>');
        $this->line('  <fg=gray>  • Entity Framework Core database migration init container (dotnet ef database update)</>');
        $this->line('  <fg=gray>  • Health check endpoint at /healthz</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Run `dotnet new webapi` inside a .NET 9 SDK Docker container.
     */
    protected function runDotnetNewWebapi(string $appName, string $baseDir): void
    {
        $this->laraKubeInfo('Pulling .NET 9 SDK builder image...');
        Process::forever()->run('docker pull mcr.microsoft.com/dotnet/sdk:9.0');

        $uid = $this->hostUid();
        $gid = $this->hostGid();

        $cmd = "docker run --rm -it -v $baseDir:/app -w /app --user root mcr.microsoft.com/dotnet/sdk:9.0"
            ." sh -c 'dotnet new webapi -o $appName --no-https'";

        passthru($cmd);

        // Chown back to host user
        if (is_dir("$baseDir/$appName")) {
            $this->runStreaming(
                "docker run --rm -v $baseDir:/app --user root mcr.microsoft.com/dotnet/sdk:9.0 chown -R $uid:$gid /app/$appName",
            );
        }
    }

    /**
     * Generate Program.cs for .NET 9 Web API with health check endpoint.
     */
    protected function generateDotnetProgramCs(string $projectDir): void
    {
        $programCs = <<<'CS'
var builder = WebApplication.CreateBuilder(args);

builder.Services.AddEndpointsApiExplorer();
builder.Services.AddSwaggerGen();
builder.Services.AddHealthChecks();

var app = builder.Build();

if (app.Environment.IsDevelopment())
{
    app.UseSwagger();
    app.UseSwaggerUI();
}

app.MapHealthChecks("/healthz");

app.MapGet("/", () => new { message = "Welcome to .NET 9 Web API on LaraKube!", status = "ok" });

app.Run();
CS;

        file_put_contents("$projectDir/Program.cs", $programCs);
        $this->laraKubeInfo('Generated Program.cs with /healthz endpoint.');
    }
}
