<?php

namespace App\Commands\Axum;

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

class AxumNewCommand extends Command
{
    use AsksServerStack, CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'axum:new
                            {name? : The name of the Axum application}
                            {--fast : Skip wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new Axum (Rust) web application with Kubernetes infrastructure (Axum + Tokio + SQLx)';

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
            label: 'What is the name of your Axum application?',
            placeholder: 'my-axum-app',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        $database = $this->askDatabase(AppFramework::AXUM, 'Which database engine would you like to use? (SQLx compile-time SQL)');
        $cacheDriver = $this->askCache(AppFramework::AXUM);
        $objectStorage = $this->askStorage(AppFramework::AXUM);
        $scoutDriver = $this->askSearch(AppFramework::AXUM);

        // Build ConfigData
        $config = new ConfigData;
        $config->setIsScaffolding(true);
        $config->setName($appName);
        $config->setPath($projectDir);
        $config->setEnvironments(['local']);
        $config->framework = AppFramework::AXUM;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding Axum (Rust 1.80): $appName...");

        // 5. Create directory structure
        if (! is_dir($projectDir)) {
            mkdir($projectDir, 0o755, true);
        }

        // 6. Generate Cargo.toml and src/main.rs
        $this->generateAxumScaffolding($projectDir, $appName);

        // 7. Generate K8s manifests
        $this->withSpin('Orchestrating Axum infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config);
        });

        $this->laraKubeInfo("✅ Axum (Rust) project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your Axum application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Features configured:</>');
        $this->line('  <fg=gray>  • Axum framework + Tokio async runtime + SQLx</>');
        $this->line('  <fg=gray>  • Multi-stage Docker build (rust:1.80-alpine → ~10MB Alpine binary)</>');
        $this->line('  <fg=gray>  • sqlx migrate init container</>');
        $this->line('  <fg=gray>  • Health check endpoint at /healthz</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Generate Rust Axum src/main.rs and Cargo.toml.
     */
    protected function generateAxumScaffolding(string $projectDir, string $appName): void
    {
        $cargoToml = <<<TOML
[package]
name = "$appName"
version = "0.1.0"
edition = "2021"

[dependencies]
axum = "0.7"
tokio = { version = "1.0", features = ["full"] }
serde = { version = "1.0", features = ["derive"] }
serde_json = "1.0"
tracing = "0.1"
tracing-subscriber = "0.3"
TOML;
        file_put_contents("$projectDir/Cargo.toml", $cargoToml);

        $srcDir = "$projectDir/src";
        if (! is_dir($srcDir)) {
            mkdir($srcDir, 0o755, true);
        }

        $mainRs = <<<'RS'
use axum::{routing::get, Json, Router};
use serde_json::{json, Value};
use std::net::SocketAddr;

#[tokio::main]
async fn main() {
    tracing_subscriber::fmt::init();

    let app = Router::new()
        .route("/healthz", get(healthz))
        .route("/", get(root));

    let addr = SocketAddr::from(([0, 0, 0, 0], 8080));
    tracing::info!("Axum listening on {}", addr);
    let listener = tokio::net::TcpListener::bind(addr).await.unwrap();
    axum::serve(listener, app).await.unwrap();
}

async fn healthz() -> Json<Value> {
    Json(json!({ "status": "ok" }))
}

async fn root() -> Json<Value> {
    Json(json!({ "message": "Welcome to Axum (Rust) on LaraKube!" }))
}
RS;
        file_put_contents("$srcDir/main.rs", $mainRs);

        $this->laraKubeInfo('Generated Cargo.toml and src/main.rs.');
    }
}
