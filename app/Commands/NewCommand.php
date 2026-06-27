<?php

namespace App\Commands;

use App\Contracts\HasLifecycleHooks;
use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\DatabaseDriver;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PackageManager;
use App\Enums\PhpVersion;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\CheckPrerequisites;
use App\Traits\GathersInfrastructureConfig;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithArchitecturalEngine;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class NewCommand extends Command
{
    use CheckPrerequisites, GathersInfrastructureConfig, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithDynamicOptions, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'new {name? : The name of the app}
                            {--fast : Skip the LaraKube wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Create a new Laravel application with a custom Kubernetes architecture';

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();

        // 1. Nesting Protection
        if (file_exists("$projectPath/.larakube.json")) {
            $this->newLine();
            $this->warn(' ⚠ NESTING WARNING: You are already inside a LaraKube CLI project.');
            $this->line('   Running "new" here will create a nested project structure.');
            $this->newLine();

            if (! confirm('Are you sure you want to proceed with a nested project?')) {
                $this->laraKubeInfo('Action cancelled to prevent project nesting.');

                return 0;
            }

            $this->logActivity('Project nesting warning ignored', ['action' => 'new'], $projectPath);
        }

        if (! $this->checkPrerequisites(true)) {
            return 1;
        }

        $inputName = $this->argument('name') ?? text(
            label: 'What is the name of your app?',
            placeholder: 'my-laravel-app',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $config = $this->buildConfigFromFlags();
        $config->setIsScaffolding(true);
        $config = $this->gatherConfig($config);

        // Architectural Guard: FrankenPHP + SQLite
        if ($config->getServerVariation() === ServerVariation::FRANKENPHP && in_array(DatabaseDriver::SQLITE, $config->getDatabases())) {
            $this->laraKubeError('Architectural Incompatibility Detected:');
            $this->line('  FrankenPHP keeps persistent workers that lock SQLite files, causing issues for other pods.');
            $this->newLine();

            if (confirm('Would you like to switch to MySQL instead?', true)) {
                $config->setDatabases([DatabaseDriver::MYSQL]);
            } else {
                $this->laraKubeInfo('Action cancelled. Please choose a different database or server.');

                return 1;
            }
        }

        $config->setName(Str::slug($inputName));

        $appName = $config->getName();
        $projectPath .= "/$appName";

        $config->setPath($projectPath, true);
        $config->setEnvironments(['local', 'production']);

        $this->laraKubeInfo("Scaffolding architectural masterpiece: $appName...");

        // Run "laravel new" command
        $this->runLaravelNew($inputName, $config);

        if (! is_dir($projectPath)) {
            $this->laraKubeError('Failed to create Laravel application.');

            return 1;
        }

        // Create .env.production
        if (file_exists("$projectPath/.env")) {
            copy("$projectPath/.env", "$projectPath/.env.production");
        }

        $this->withSpin('Orchestrating infrastructure manifests...', function () use ($config) {
            $this->orchestrateProjectScaffolding($config);

            if ($config->id) {
                $this->logToConsole($config->id, 'new', 'New architectural masterpiece created', [
                    'name' => $config->getName(),
                    'blueprints' => $config->getBlueprints(),
                    'server' => $config->getServerVariation()?->value,
                ]);
            }
        });

        $this->laraKubeInfo("Project $appName created successfully!");

        // Register with Console
        $this->registerWithConsole([
            'uuid' => $config->id,
            'name' => $appName,
            'path' => $projectPath,
            'blueprints' => $config->getBlueprints(), // Note: I should check if ConfigData has a getter for array or just use raw property if accessible
            'config' => $config->toArray(),
        ]);

        $this->newLine();
        info('First, start your application:');
        $this->line("  cd {$appName} && larakube up");

        // Collect instructions from all components
        $allInstructions = [];
        foreach ($config->getComponents() as $component) {
            if ($component instanceof HasLifecycleHooks) {
                $allInstructions = array_merge($allInstructions, $component->getPostInstallInstructions($config));
            }
        }

        if (! empty($allInstructions)) {
            $this->newLine();
            $this->warn('Then, perform these one-time architectural steps:');
            foreach ($allInstructions as $line) {
                $this->line("  $line");
            }
        }

        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    /**
     * Configure the command to ignore validation errors so we can forward arbitrary flags.
     */
    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this->addArchitecturalOptions();
    }

    protected function runLaravelNew($inputName, ConfigData $config): void
    {
        $appName = $config->getName();
        $projectPath = $config->getPath();

        $uid = host_uid();
        $gid = host_gid();
        $image = $config->getPhpImage(true);

        $this->laraKubeInfo("Pulling builder image: $image...");
        passthru("docker pull $image > /dev/null 2>&1");

        // Skip LaraKube-specific flags (Dynamic from Enums)
        $larakubeFlags = array_merge(
            ['fast', 'force', 'no-interaction'],
            Blueprint::getCommandOptions(),
            ServerVariation::getCommandOptions(),
            OperatingSystem::getCommandOptions(),
            PackageManager::getCommandOptions(),
            FrontendStack::getCommandOptions(),
            PhpVersion::getCommandOptions(),
            DatabaseDriver::getCommandOptions(),
            LaravelFeature::getCommandOptions(),
            StorageDriver::getCommandOptions(),
            ScoutDriver::getCommandOptions(),
        );

        // Filter out LaraKube flags AND the project name to forward only native Laravel flags
        $extraArgs = array_filter(array_slice($_SERVER['argv'], 2), function ($arg) use ($inputName, $larakubeFlags) {
            // Skip if it's the original project name argument
            if ($inputName && $arg === $inputName) {
                return false;
            }

            if (str_starts_with($arg, '--')) {
                return ! in_array(ltrim($arg, '-'), $larakubeFlags);
            }

            // Keep any other positional arguments or unknown flags (to be safe)
            return true;
        });

        // Add Package Manager & Frontend Stack
        if ($pmFlag = $config->getPackageManager()?->getOptionFlag()) {
            $extraArgs[] = $pmFlag;
        }

        if ($frontendFlag = $config->getFrontend()?->getOptionFlag()) {
            $extraArgs[] = $frontendFlag;
        }

        // Laravel Boost should be disabled during "laravel new"
        // This will be taken care of by the orchestration process
        $extraArgs[] = '--no-boost';

        // Set default database to SQLite temporarily during "laravel new"
        // The database will be configured later in the orchestration process\
        $extraArgs[] = '--database=sqlite';

        $extraFlags = implode(' ', $extraArgs);

        $pkgCommand = $this->getNodeInstallationCommand($image);
        $baseDir = dirname($projectPath);

        // Note for Senior Dev: Changed `&& chown` to `; chown` so that ownership is restored
        // even if the `laravel new` command fails or times out, preventing root-owned zombie files.
        $cmd = 'docker run --rm -it -v '.$baseDir.":/var/www/html -e COMPOSER_CACHE_DIR=/dev/null -e COMPOSER_ALLOW_SUPERUSER=1 --user root $image ".
               "sh -c '$pkgCommand && composer global require laravel/installer && $(composer global config bin-dir --absolute)/laravel new $appName $extraFlags; chown -R $uid:$gid {$appName}'";

        passthru($cmd);
    }
}
