<?php

namespace App\Commands\Axum;

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
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class AxumNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

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

        // 1. DatabaseDriver — PostgreSQL (recommended via SQLx), MySQL, MariaDB
        $allowedDbs = [
            DatabaseDriver::POSTGRESQL->value => DatabaseDriver::POSTGRESQL->getLabel().' (Recommended via SQLx)',
            DatabaseDriver::MYSQL->value => DatabaseDriver::MYSQL->getLabel(),
            DatabaseDriver::MARIADB->value => DatabaseDriver::MARIADB->getLabel(),
        ];

        $dbValue = $this->option('fast')
            ? DatabaseDriver::POSTGRESQL->value
            : select(
                label: 'Which database engine would you like to use? (SQLx compile-time SQL)',
                options: $allowedDbs,
                default: DatabaseDriver::POSTGRESQL->value,
            );
        $database = DatabaseDriver::from($dbValue);

        // 2. CacheDriver — Redis (recommended via redis-rs)
        $allowedCaches = [
            CacheDriver::REDIS->value => CacheDriver::REDIS->getLabel().' (Recommended via redis-rs)',
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

        // 3. StorageDriver — S3-compatible object storage via aws-sdk-s3
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
        $this->withSpin('Orchestrating Axum infrastructure manifests...', function () use ($config) {
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
