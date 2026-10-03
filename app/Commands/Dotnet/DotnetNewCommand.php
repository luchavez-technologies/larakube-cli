<?php

namespace App\Commands\Dotnet;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Traits\AsksServerStack;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class DotnetNewCommand extends Command
{
    use AsksServerStack, CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, StreamsProcessOutput, SyncsClusterSecrets;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'dotnet:new
                            {name? : The name of the .NET Core application}
                            {--fast : Skip wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new ASP.NET Core 10.0 Web API application with Kubernetes infrastructure';

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

        $database = $this->askDatabase(AppFramework::DOTNET, 'Which database engine would you like to use? (Entity Framework Core)');
        $cacheDriver = $this->askCache(AppFramework::DOTNET);
        $objectStorage = $this->askStorage(AppFramework::DOTNET);
        $scoutDriver = $this->askSearch(AppFramework::DOTNET);

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

        $this->laraKubeInfo("Scaffolding ASP.NET Core 10.0 Web API: $appName...");

        // 5. Run `dotnet new webapi` inside a .NET 10 SDK Docker container
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
        $this->line('  <fg=gray>  • ASP.NET Core 10.0 Alpine runner (mcr.microsoft.com/dotnet/aspnet:10.0-alpine)</>');
        $this->line('  <fg=gray>  • Production image built from Dockerfile.dotnet (SDK build, then the slim runtime)</>');
        $this->line('  <fg=gray>  • Health check endpoint at /healthz</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Run `dotnet new webapi` inside a .NET 10 SDK Docker container.
     */
    protected function runDotnetNewWebapi(string $appName, string $baseDir): void
    {
        $this->laraKubeInfo('Pulling .NET 10 SDK builder image...');
        Process::forever()->run($this->pullImageCommand('mcr.microsoft.com/dotnet/sdk:10.0'));

        $runtime = $this->containerRuntime();

        $uid = $this->hostUid();
        $gid = $this->hostGid();

        $cmd = "$runtime run --rm -v $baseDir:/app -w /app --user root mcr.microsoft.com/dotnet/sdk:10.0"
            ." sh -c 'dotnet new webapi -o $appName --no-https'";

        $this->runInteractive($cmd);

        // Chown back to host user
        if (is_dir("$baseDir/$appName")) {
            $this->runStreaming(
                "$runtime run --rm -v $baseDir:/app --user root mcr.microsoft.com/dotnet/sdk:10.0 chown -R {$this->containerChownSpec($uid, $gid)} /app/$appName",
            );
        }
    }

    /**
     * Generate Program.cs for .NET 10 Web API with health check endpoint.
     */
    protected function generateDotnetProgramCs(string $projectDir): void
    {
        $programCs = <<<'CS'
var builder = WebApplication.CreateBuilder(args);

builder.Services.AddOpenApi();
builder.Services.AddHealthChecks();

var app = builder.Build();

if (app.Environment.IsDevelopment())
{
    app.MapOpenApi();
}

app.MapHealthChecks("/healthz");

app.MapGet("/", () => new { message = "Welcome to .NET 10 Web API on LaraKube!", status = "ok" });

app.Run();
CS;

        file_put_contents("$projectDir/Program.cs", $programCs);
        $this->laraKubeInfo('Generated Program.cs with /healthz endpoint.');
    }
}
