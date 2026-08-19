<?php

namespace App\Commands\Wordpress;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\PhpVersion;
use App\Enums\SearchDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\CheckPrerequisites;
use App\Traits\GathersInfrastructureConfig;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithArchitecturalEngine;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class WordpressNewCommand extends Command
{
    use CheckPrerequisites, GathersInfrastructureConfig, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'wordpress:new
                            {name? : The name of the WordPress site}
                            {--fast : Skip wizard and use ideal defaults}
                            {--no-plex : Skip Plex Commons auto-provisioning and use self-hosted databases}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new WordPress (Bedrock) project with Kubernetes infrastructure';

    /**
     * Backward-compatible alias for those who prefer the shorthand.
     *
     * @var array<int, string>
     */
    protected $aliases = ['wp:new'];

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
            label: 'What is the name of your WordPress site?',
            placeholder: 'my-wordpress-site',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        // 1. PHP Version
        $version = $this->option('fast')
            ? PhpVersion::PHP_8_4->value
            : select(
                label: 'Which PHP version would you like to use?',
                options: collect(PhpVersion::cases())
                    ->filter(fn ($v) => (float) $v->value >= 8.2)
                    ->mapWithKeys(fn ($v) => [$v->value => $v->getLabel()])
                    ->all(),
                default: PhpVersion::PHP_8_4->value,
            );
        $phpVersion = PhpVersion::from($version);

        // 2. DatabaseDriver — MySQL and MariaDB ONLY for WordPress (plan §2a)
        $allowedDbs = [
            DatabaseDriver::MYSQL->value => DatabaseDriver::MYSQL->getLabel(),
            DatabaseDriver::MARIADB->value => DatabaseDriver::MARIADB->getLabel(),
        ];

        $dbValue = $this->option('fast')
            ? DatabaseDriver::MYSQL->value
            : select(
                label: 'Which database engine? (WordPress supports MySQL/MariaDB only)',
                options: $allowedDbs,
                default: DatabaseDriver::MYSQL->value,
            );
        $database = DatabaseDriver::from($dbValue);

        // 3. CacheDriver — Redis or Memcached (plan §2b; database hidden for WordPress)
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

        // 4. StorageDriver — MANDATORY for WordPress (plan §2d)
        // Pods are ephemeral; PVC is explicitly not offered.
        $allowedStorages = [
            StorageDriver::MINIO->value => StorageDriver::MINIO->getLabel().' (Recommended)',
            StorageDriver::SEAWEEDFS->value => StorageDriver::SEAWEEDFS->getLabel(),
            StorageDriver::GARAGE->value => StorageDriver::GARAGE->getLabel(),
        ];

        $this->laraKubeInfo('WordPress media offload: A StorageDriver is mandatory (humanmade/s3-uploads will be installed).');

        $storageValue = $this->option('fast')
            ? StorageDriver::MINIO->value
            : select(
                label: 'Which S3-compatible object storage would you like to use for media uploads?',
                options: $allowedStorages,
                default: StorageDriver::MINIO->value,
            );
        $objectStorage = StorageDriver::from($storageValue);

        // 5. SearchDriver — Typesense/Meilisearch deployment only; Scout is hidden (plan §2c)
        $allowedSearch = [
            'none' => 'None',
            SearchDriver::TYPESENSE->value => SearchDriver::TYPESENSE->getLabel().' ("Search with Typesense" plugin)',
            SearchDriver::MEILISEARCH->value => SearchDriver::MEILISEARCH->getLabel().' (⚠ no maintained official WP plugin)',
        ];

        $searchValue = $this->option('fast')
            ? 'none'
            : select(
                label: 'Which search deployment would you like to add? (WordPress plugin installation required manually)',
                options: $allowedSearch,
                default: 'none',
            );

        if ($searchValue === SearchDriver::MEILISEARCH->value) {
            warning('Meilisearch has no officially maintained WordPress plugin. You will need to install a community plugin manually.');
        }

        $scoutDriver = SearchDriver::tryFrom($searchValue);

        // Build ConfigData
        $config = new ConfigData;
        $config->setIsScaffolding(true);
        $config->setName($appName);
        $config->setPath($projectDir);
        $config->setEnvironments(['local']);
        $config->framework = AppFramework::WORDPRESS;
        $config->phpVersion = $phpVersion;
        $config->serverVariation = ServerVariation::FPM_NGINX;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        $config->setObjectStorage($objectStorage); // mandatory
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding WordPress (Bedrock): $appName...");

        // Auto-provision Plex Commons database/services for WordPress (unless --no-plex)
        $plexCredentials = null;
        if (! $this->option('no-plex')) {
            $plexCredentials = $this->ensurePlexProvisionedForApp($config);
        }

        if ($plexCredentials !== null) {
            $config->addEnvironment('local');
            $config->environments['local']->plex = array_values(array_unique(array_merge($config->environments['local']->plex, $plexCredentials['services'] ?? [])));
        }

        // 6. Run composer create-project roots/bedrock inside Docker
        $this->runBedrockNew($appName, $config, $projectPath);

        if (! is_dir($projectDir)) {
            $this->laraKubeError('Failed to create WordPress (Bedrock) application.');

            return 1;
        }

        // 7. Generate K8s manifests
        $this->withSpin('Orchestrating WordPress infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config);
        });

        $this->laraKubeInfo("✅ WordPress (Bedrock) project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your WordPress application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Important environment variables have been generated in .infrastructure/k8s/secrets/</>');
        $this->line('  <fg=gray>WP-Cron is disabled; a Kubernetes CronJob runs every 5 minutes instead.</>');

        if ($scoutDriver) {
            $this->newLine();
            $this->line("  <fg=gray>Search infrastructure (</><fg=yellow>{$scoutDriver->getLabel()}</><fg=gray>) has been provisioned.</>");
            $this->line('  <fg=gray>Install the corresponding WordPress plugin to connect it.</>');
        }

        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Scaffold a Bedrock project via `composer create-project roots/bedrock`
     * inside an SSU Docker container (mirrors NewCommand::runLaravelNew pattern).
     */
    protected function runBedrockNew(string $appName, ConfigData $config, string $baseDir): void
    {
        $uid = $this->hostUid();
        $gid = $this->hostGid();
        $image = $config->getPhpImage(true); // CLI image

        $this->laraKubeInfo("Pulling builder image: $image...");
        Process::forever()->run("docker pull $image");

        $cmd = "docker run --rm -it -v $baseDir:/var/www/html"
            .' -e COMPOSER_CACHE_DIR=/dev/null'
            .' -e COMPOSER_ALLOW_SUPERUSER=1'
            .' -e SHOW_WELCOME_MESSAGE=false'
            ." --user root $image"
            ." sh -c 'composer create-project roots/bedrock $appName --prefer-dist --no-interaction'";

        passthru($cmd);

        // Chown back to host user
        if (is_dir("$baseDir/$appName")) {
            $this->runStreaming(
                "docker run --rm -v $baseDir:/var/www/html --user root -e SHOW_WELCOME_MESSAGE=false $image chown -R $uid:$gid /var/www/html/$appName",
            );
        }
    }
}
