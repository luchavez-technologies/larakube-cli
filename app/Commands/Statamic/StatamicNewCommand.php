<?php

namespace App\Commands\Statamic;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\PhpVersion;
use App\Enums\SearchDriver;
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

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class StatamicNewCommand extends Command
{
    use CheckPrerequisites, GathersInfrastructureConfig, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'statamic:new
                            {name? : The name of the Statamic site}
                            {--fast : Skip wizard and use ideal defaults}
                            {--no-plex : Skip Plex Commons auto-provisioning and use self-hosted databases}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new Statamic CMS project with Kubernetes infrastructure';

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->plexContext = null;

        $projectPath = getcwd();

        if (! $this->checkPrerequisites(false)) {
            return 1;
        }

        $inputName = $this->argument('name') ?? text(
            label: 'What is the name of your Statamic site?',
            placeholder: 'my-statamic-site',
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

        // 2. DatabaseDriver — MySQL, MariaDB, PostgreSQL only (plan §2a)
        $allowedDbs = collect(DatabaseDriver::cases())
            ->filter(fn ($d) => in_array($d, [
                DatabaseDriver::MYSQL,
                DatabaseDriver::MARIADB,
                DatabaseDriver::POSTGRESQL,
            ], true))
            ->mapWithKeys(fn ($d) => [$d->value => $d->getLabel()])
            ->all();

        $dbValue = $this->option('fast')
            ? DatabaseDriver::MYSQL->value
            : select(
                label: 'Which database engine would you like to use?',
                options: $allowedDbs,
                default: DatabaseDriver::MYSQL->value,
            );
        $database = DatabaseDriver::from($dbValue);

        // 3. CacheDriver — all three available for Statamic (plan §2b)
        $allowedCaches = collect(CacheDriver::cases())
            ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
            ->all();

        $cacheValue = $this->option('fast')
            ? CacheDriver::REDIS->value
            : select(
                label: 'Which cache driver would you like to use?',
                options: $allowedCaches,
                default: CacheDriver::REDIS->value,
            );
        $cacheDriver = CacheDriver::from($cacheValue);

        // 4. StorageDriver (plan §2d)
        $allowedStorages = collect(StorageDriver::cases())
            ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])
            ->all();

        $storageValue = $this->option('fast')
            ? StorageDriver::MINIO->value
            : select(
                label: 'Which S3-compatible object storage would you like to use for Statamic assets?',
                options: array_merge(['none' => 'None (local filesystem)'], $allowedStorages),
                default: StorageDriver::MINIO->value,
            );
        $objectStorage = StorageDriver::tryFrom($storageValue);

        // 5. SearchDriver — all three via Scout (plan §2c)
        $allowedSearch = collect(SearchDriver::cases())
            ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])
            ->all();

        $searchValue = $this->option('fast')
            ? 'none'
            : select(
                label: 'Which search driver would you like to use?',
                options: array_merge(['none' => 'None'], $allowedSearch),
                default: 'none',
            );
        $scoutDriver = SearchDriver::tryFrom($searchValue);

        // Build ConfigData
        $config = new ConfigData;
        $config->setIsScaffolding(true);
        $config->setName($appName);
        $config->setPath($projectDir);
        $config->setEnvironments(['local']);
        $config->framework = AppFramework::STATAMIC;
        $config->phpVersion = $phpVersion;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding Statamic: $appName...");

        // Auto-provision Plex Commons database for the app (unless SQLite or --no-plex)
        $plexCredentials = null;
        if (! $this->option('no-plex')) {
            $plexCredentials = $this->ensurePlexProvisionedForApp($config);
        }

        // 6. Run composer create-project inside Docker
        $this->runStatamicNew($appName, $config, $projectPath, $plexCredentials);

        if (! is_dir($projectDir)) {
            $this->laraKubeError('Failed to create Statamic application.');

            return 1;
        }

        // If Plex provisioned successfully, mark the local environment as Plex-backed
        if ($plexCredentials !== null) {
            $config->addEnvironment('local');
            $config->environments['local']->plex = array_unique(array_merge($config->environments['local']->plex, $plexCredentials['services']));
        }

        // 7. Generate K8s manifests
        $this->withSpin('Orchestrating Statamic infrastructure manifests...', function () use ($config) {
            $this->orchestrateProjectScaffolding($config);
        });

        $this->laraKubeInfo("✅ Statamic project '$appName' created successfully!");
        $this->newLine();
        if (confirm('Would you like to start your Statamic application now with `larakube up`?', true)) {
            chdir($projectDir);

            return $this->call('up');
        }

        $this->line('  <fg=gray>To start your Statamic application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>To create your first super user, run:</>');
        $this->line('  <fg=yellow>larakube art make:statamic-user</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Scaffold a Statamic project using `composer create-project statamic/statamic`
     * inside an SSU Docker container (mirrors NewCommand::runLaravelNew pattern).
     */
    protected function runStatamicNew(string $appName, ConfigData $config, string $baseDir, ?array $plexCredentials = null): void
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
            ." sh -c 'composer create-project statamic/statamic $appName --prefer-dist --no-interaction'";

        passthru($cmd);

        // Chown back to host user
        if (is_dir("$baseDir/$appName")) {
            $this->runStreaming(
                "docker run --rm -v $baseDir:/var/www/html --user root -e SHOW_WELCOME_MESSAGE=false $image chown -R $uid:$gid /var/www/html/$appName",
            );
        }
    }
}
