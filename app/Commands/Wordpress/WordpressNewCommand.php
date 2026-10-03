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
use App\Traits\AsksServerStack;
use App\Traits\CheckPrerequisites;
use App\Traits\GathersInfrastructureConfig;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithArchitecturalEngine;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
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
    use AsksServerStack, CheckPrerequisites, GathersInfrastructureConfig, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, StreamsProcessOutput, SyncsClusterSecrets;

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

        $supportedPhp = array_values(array_filter(PhpVersion::cases(), fn (PhpVersion $v): bool => (float) $v->value >= 8.2));
        $version = $this->flaggedCase(PhpVersion::class, $supportedPhp)?->value ?? ($this->option('fast')
            ? PhpVersion::PHP_8_4->value
            : select(
                label: 'Which PHP version would you like to use?',
                options: collect($supportedPhp)
                    ->mapWithKeys(fn ($v) => [$v->value => $v->getLabel()])
                    ->all(),
                default: PhpVersion::PHP_8_4->value,
            ));
        $phpVersion = PhpVersion::from($version);

        $database = $this->askDatabase(AppFramework::WORDPRESS, 'Which database engine? (WordPress supports MySQL/MariaDB only)');
        $cacheDriver = $this->askCache(AppFramework::WORDPRESS);
        $this->laraKubeInfo('WordPress media offload: A StorageDriver is mandatory (humanmade/s3-uploads will be installed).');

        $objectStorage = $this->askStorage(AppFramework::WORDPRESS, 'Which S3-compatible object storage would you like to use for media uploads?');
        $scoutDriver = $this->askSearch(AppFramework::WORDPRESS, 'Which search deployment would you like to add? (WordPress plugin installation required manually)');

        if ($scoutDriver === SearchDriver::MEILISEARCH) {
            warning('Meilisearch has no officially maintained WordPress plugin. You will need to install a community plugin manually.');
        }

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

        // Join the Commons AFTER the project exists — plex:join writes the
        // tenant .env and the `managed` list the manifest generator reads.
        if (! $this->option('no-plex')) {
            $this->joinPlexCommons($config, $projectDir);
        }

        $this->laraKubeInfo("✅ WordPress (Bedrock) project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your WordPress application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->line('  <fg=gray>Then open the site and WordPress\'s installer takes it from there.</>');

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

    protected function configure(): void
    {
        parent::configure();

        $this->addAnswerFlags(PhpVersion::class, DatabaseDriver::class, CacheDriver::class, StorageDriver::class, SearchDriver::class);
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
        Process::forever()->run($this->pullImageCommand($image));

        $runtime = $this->containerRuntime();

        $envFlags = '-e COMPOSER_CACHE_DIR=/dev/null -e COMPOSER_ALLOW_SUPERUSER=1 -e SHOW_WELCOME_MESSAGE=false';
        $scaffold = "composer create-project roots/bedrock $appName --prefer-dist --no-interaction";

        // Hand the terminal to composer when there is one, so its progress
        // streams live; otherwise (CI, a piped run, --no-interaction) drop -it
        // and go through the Process facade, since `<runtime> run -it` fails
        // outright without a TTY. Bedrock has no wizard of its own, so the
        // scaffold command is identical either way — this is purely about
        // whether a terminal is handed over, mirroring ScaffoldsInNode.
        if ($this->canHandOverTerminal()) {
            $this->runInteractive("$runtime run --rm -it -v $baseDir:/var/www/html $envFlags --user root $image sh -c '$scaffold'");
        } else {
            $this->withSpin("Scaffolding WordPress (Bedrock): $appName...", fn (): bool => Process::forever()->run(
                "$runtime run --rm -v $baseDir:/var/www/html $envFlags --user root $image sh -c '$scaffold'",
            )->successful());
        }

        // Chown back to host user
        if (is_dir("$baseDir/$appName")) {
            $this->runStreaming(
                "$runtime run --rm -v $baseDir:/var/www/html --user root -e SHOW_WELCOME_MESSAGE=false $image chown -R {$this->containerChownSpec($uid, $gid)} /var/www/html/$appName",
            );
        }
    }

    /**
     * Whether the scaffolder can be handed the terminal: a real TTY, not a test
     * run, and no --fast/--no-interaction opt-out. Mirrors
     * ScaffoldsInNode::scaffolderCanPrompt for this PHP/composer scaffolder.
     */
    private function canHandOverTerminal(): bool
    {
        return ! app()->runningUnitTests()
            && ! (bool) $this->option('no-interaction')
            && ! (bool) $this->option('fast')
            && stream_isatty(STDIN);
    }
}
