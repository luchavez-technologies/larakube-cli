<?php

namespace App\Commands\Nestjs;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Traits\AsksServerStack;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\PreparesNestjsProject;
use App\Traits\ScaffoldsInNode;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class NestjsNewCommand extends Command
{
    use AsksServerStack, CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, PreparesNestjsProject, ScaffoldsInNode, SyncsClusterSecrets;

    /** Same Node the dev pod and the image build use. */
    protected const NODE_IMAGE = 'docker.io/library/node:24-alpine';

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'nestjs:new
                            {name? : The name of the NestJS application}
                            {--fast : Skip wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new NestJS application with Kubernetes infrastructure (TypeScript + Prisma + Terminus)';

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
            label: 'What is the name of your NestJS application?',
            placeholder: 'my-nestjs-app',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        $database = $this->askDatabase(AppFramework::NESTJS, 'Which database engine would you like to use? (Prisma ORM)');
        $cacheDriver = $this->askCache(AppFramework::NESTJS);
        $objectStorage = $this->askStorage(AppFramework::NESTJS);
        $scoutDriver = $this->askSearch(AppFramework::NESTJS);

        // Build ConfigData
        $config = new ConfigData;
        $config->setIsScaffolding(true);
        $config->setName($appName);
        $config->setPath($projectDir);
        $config->setEnvironments(['local']);
        $config->framework = AppFramework::NESTJS;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding NestJS: $appName...");

        // 5. Scaffold with the Nest CLI inside Node (Docker or Podman).
        if (! $this->runNestCliNew($appName, $projectPath)) {
            $this->laraKubeError('Failed to create NestJS application.');

            return 1;
        }

        $this->addNestjsHealthController($projectDir);

        // 6. Generate K8s manifests
        $this->withSpin('Orchestrating NestJS infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false);
        });

        $this->laraKubeInfo("✅ NestJS project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your NestJS application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Features configured:</>');
        $this->line('  <fg=gray>  • NestJS TypeScript modular architecture (Node.js 24 Alpine)</>');
        $this->line('  <fg=gray>  • Migrations run before each rollout once the project has prisma/schema.prisma</>');
        $this->line('  <fg=gray>  • Health check endpoint at /healthz</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Run the Nest CLI inside Node via ScaffoldsInNode, so it works under Docker
     * or Podman and with or without a terminal. --no-observe answers the one
     * prompt `nest new` would otherwise raise; git and the package manager are
     * pinned so the Dockerfile's `npm ci` finds a package-lock.json.
     */
    protected function runNestCliNew(string $appName, string $baseDir): bool
    {
        $command = "npx --yes @nestjs/cli@latest new {$appName} --package-manager npm --skip-git --strict --no-observe";

        return $this->scaffoldInNode($appName, $baseDir, 'NestJS app', $command, $command);
    }
}
