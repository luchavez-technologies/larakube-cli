<?php

namespace App\Commands\Gin;

use App\Data\ConfigData;
use App\Enums\AppFramework;
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

class GinNewCommand extends Command
{
    use AsksServerStack, CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'gin:new
                            {name? : The name of the Gin application}
                            {--fast : Skip wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new Gin (Go) web application with Kubernetes infrastructure (Gin + GORM)';

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
            label: 'What is the name of your Gin application?',
            placeholder: 'my-gin-app',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        $database = $this->askDatabase(AppFramework::GIN, 'Which database engine would you like to use? (GORM)');
        $cacheDriver = $this->askCache(AppFramework::GIN);
        $objectStorage = $this->askStorage(AppFramework::GIN);
        $scoutDriver = $this->askSearch(AppFramework::GIN);

        // Build ConfigData
        $config = new ConfigData;
        $config->setIsScaffolding(true);
        $config->setName($appName);
        $config->setPath($projectDir);
        $config->setEnvironments(['local']);
        $config->framework = AppFramework::GIN;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding Gin (Go 1.23): $appName...");

        // 5. Create directory structure
        if (! is_dir($projectDir)) {
            mkdir($projectDir, 0o755, true);
        }

        // 6. Generate main.go and go.mod
        $this->generateGinScaffolding($projectDir, $appName);

        // 7. Generate K8s manifests
        $this->withSpin('Orchestrating Gin infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config);
        });

        $this->laraKubeInfo("✅ Gin (Go) project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your Gin application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Features configured:</>');
        $this->line('  <fg=gray>  • Gin framework + GORM ORM</>');
        $this->line('  <fg=gray>  • Multi-stage Docker build (golang:1.23-alpine → 15MB Alpine binary)</>');
        $this->line('  <fg=gray>  • golang-migrate database migration init container</>');
        $this->line('  <fg=gray>  • Health check endpoint at /healthz</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Generate Go Gin main.go and go.mod.
     */
    protected function generateGinScaffolding(string $projectDir, string $appName): void
    {
        file_put_contents("$projectDir/go.mod", "module $appName\n\ngo 1.23\n");

        $mainGo = <<<'GO'
package main

import (
	"net/http"

	"github.com/gin-gonic/gin"
)

func main() {
	r := gin.Default()

	r.GET("/healthz", func(c *gin.Context) {
		c.JSON(http.StatusOK, gin.H{
			"status": "ok",
		})
	})

	r.GET("/", func(c *gin.Context) {
		c.JSON(http.StatusOK, gin.H{
			"message": "Welcome to Gin (Go) on LaraKube!",
		})
	})

	r.Run(":8080")
}
GO;

        file_put_contents("$projectDir/main.go", $mainGo);
        $this->laraKubeInfo('Generated main.go and go.mod.');
    }
}
