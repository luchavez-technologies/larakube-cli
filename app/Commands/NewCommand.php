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
use App\Enums\SearchDriver;
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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;
use Symfony\Component\Console\Input\InputOption;

class NewCommand extends Command
{
    use CheckPrerequisites, GathersInfrastructureConfig, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithDynamicOptions, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * Native `laravel new` options (installer v5.x), declared here so Symfony
     * can BIND them. ignoreValidationErrors() alone is not enough: binding
     * aborts at the first unknown option, so `larakube new --teams myapp`
     * would silently lose `myapp` (and any later flag like --fast) and fall
     * into the name prompt — a hard NonInteractiveValidationException under
     * --no-interaction. Options the architectural enums already register
     * (--react, --vue, --npm, …) are skipped at add time; value-taking
     * options are marked so their values are consumed correctly.
     *
     * Deliberately absent: `--database`. That name already belongs to
     * LaraKube CLI semantics (CacheDriver/SearchDriver's boolean "use the
     * database as this driver" flag), and database selection for the app
     * itself goes through the per-driver flags (--mysql, --pgsql, …) —
     * runLaravelNew() always scaffolds with --database=sqlite and
     * reconfigures afterwards.
     *
     * @var array<string, int>
     */
    private const LARAVEL_NEW_OPTIONS = [
        'dev' => InputOption::VALUE_NONE,
        'git' => InputOption::VALUE_NONE,
        'branch' => InputOption::VALUE_REQUIRED,
        'github' => InputOption::VALUE_OPTIONAL,
        'organization' => InputOption::VALUE_REQUIRED,
        'react' => InputOption::VALUE_NONE,
        'svelte' => InputOption::VALUE_NONE,
        'vue' => InputOption::VALUE_NONE,
        'livewire' => InputOption::VALUE_NONE,
        'livewire-class-components' => InputOption::VALUE_NONE,
        'workos' => InputOption::VALUE_NONE,
        'teams' => InputOption::VALUE_NONE,
        'no-authentication' => InputOption::VALUE_NONE,
        'pest' => InputOption::VALUE_NONE,
        'phpunit' => InputOption::VALUE_NONE,
        'npm' => InputOption::VALUE_NONE,
        'pnpm' => InputOption::VALUE_NONE,
        'bun' => InputOption::VALUE_NONE,
        'yarn' => InputOption::VALUE_NONE,
        'no-node' => InputOption::VALUE_NONE,
        'boost' => InputOption::VALUE_NONE,
        'no-boost' => InputOption::VALUE_NONE,
        'using' => InputOption::VALUE_OPTIONAL,
        'force' => InputOption::VALUE_NONE,
    ];

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

        if (! $this->checkPrerequisites(false)) {
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
        // Environments are opt-in: a fresh project starts with `local` only.
        // Cloud environments (production, staging, …) are created on demand
        // via `larakube env` or `cloud:configure`.
        $config->setEnvironments(['local']);

        $this->laraKubeInfo("Scaffolding architectural masterpiece: $appName...");

        // Run "laravel new" command
        $this->runLaravelNew($inputName, $config);

        if (! is_dir($projectPath)) {
            $this->laraKubeError('Failed to create Laravel application.');

            return 1;
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
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');

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
     * Declare every flag `laravel new` understands (so binding completes and
     * the name argument + later flags parse), and still ignore validation
     * errors as a forward-compatibility net for installer flags added after
     * this list was written.
     */
    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this->addArchitecturalOptions();

        foreach (self::LARAVEL_NEW_OPTIONS as $name => $mode) {
            if (! $this->getDefinition()->hasOption($name)) {
                $this->addOption(name: $name, mode: $mode, description: "Forwarded to 'laravel new'");
            }
        }
    }

    protected function runLaravelNew($inputName, ConfigData $config): void
    {
        $appName = $config->getName();
        $projectPath = $config->getPath();

        $uid = $this->hostUid();
        $gid = $this->hostGid();
        $image = $config->getPhpImage(true);

        $this->laraKubeInfo("Pulling builder image: $image...");
        Process::forever()->run("docker pull $image");

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
            SearchDriver::getCommandOptions(),
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

        // Set database flag to match chosen driver (pgsql, mysql, mariadb, or sqlite)
        $dbDriver = match ($config->getDatabase()) {
            DatabaseDriver::POSTGRESQL => 'pgsql',
            DatabaseDriver::MYSQL => 'mysql',
            DatabaseDriver::MARIADB => 'mariadb',
            default => 'sqlite',
        };
        $extraArgs[] = "--database={$dbDriver}";

        $extraFlags = implode(' ', $extraArgs);

        $pkgCommand = $this->getNodeInstallationCommand($image);
        $baseDir = dirname($projectPath);

        $cmd = "docker run --rm -it --add-host=host.docker.internal:host-gateway -v $baseDir:/var/www/html -e COMPOSER_CACHE_DIR=/dev/null -e COMPOSER_ALLOW_SUPERUSER=1 -e SHOW_WELCOME_MESSAGE=false -e DB_HOST=host.docker.internal --user root $image ".
               "sh -c '$pkgCommand && composer config -g bin-dir /usr/local/bin && composer global require laravel/installer && laravel new $appName $extraFlags'";

        passthru($cmd);

        // Hand ownership of the scaffolded project back to the host user. Done in a SEPARATE,
        // non-interactive container — not chained inside the -it run above, where the chown
        // could silently no-op (notably on WSL) and leave a root-owned project you'd need
        // sudo to manage. Uses the host user's real uid/gid (see InteractsWithDocker::hostUid).
        if (is_dir($projectPath)) {
            $this->runStreaming("docker run --rm -v $baseDir:/var/www/html --user root -e SHOW_WELCOME_MESSAGE=false $image chown -R $uid:$gid /var/www/html/$appName");
        }
    }
}
