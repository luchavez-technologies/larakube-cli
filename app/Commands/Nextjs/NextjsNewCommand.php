<?php

namespace App\Commands\Nextjs;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\PreparesNextjsProject;
use App\Traits\ScaffoldsInNode;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class NextjsNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, PreparesNextjsProject, ScaffoldsInNode, StreamsProcessOutput, SyncsClusterSecrets;

    /** The Node builder image the scaffolder runs in (matches the sibling frontends). */
    protected const NODE_IMAGE = 'node:24-alpine';

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'nextjs:new
                            {name? : The name of the Next.js application}
                            {--fast : Skip wizard and use ideal defaults}
                            {--no-plex : Skip Plex Commons auto-provisioning and use self-hosted database/redis}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new Next.js application with Kubernetes infrastructure (standalone output + Redis cache handler)';

    /**
     * Backward-compatible alias for those who prefer the shorthand.
     *
     * @var array<int, string>
     */
    protected $aliases = ['next:new'];

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();

        // Provisioning happens against the local cluster during scaffold, so the
        // Plex helpers use the current kube-context (see PlexContextWiringTest).
        $this->plexContext = null;

        $projectPath = getcwd();

        if (! $this->checkPrerequisites(false)) {
            return 1;
        }

        $inputName = $this->argument('name') ?? text(
            label: 'What is the name of your Next.js application?',
            placeholder: 'my-nextjs-app',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        $drivers = $this->gatherNextjsDrivers((bool) $this->option('fast'));
        $database = $drivers['database'];
        $cacheDriver = $drivers['cache'];
        $objectStorage = $drivers['storage'];
        $scoutDriver = $drivers['search'];

        // Build ConfigData
        $config = new ConfigData;
        $config->setIsScaffolding(true);
        $config->setName($appName);
        $config->setPath($projectDir);
        $config->setEnvironments(['local']);
        $config->framework = AppFramework::NEXTJS;
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        $this->laraKubeInfo("Scaffolding Next.js: $appName...");

        // 5. Run create-next-app inside Node (runtime-agnostic: Docker or Podman)
        if (! $this->runCreateNextApp($appName, $projectPath)) {
            $this->laraKubeError('Failed to create Next.js application.');

            return 1;
        }

        // 6. Patch next.config.ts for standalone output
        $this->patchNextConfig($projectDir);

        // 7. Generate Redis cache-handler + install its deps (create-next-app
        //    ships neither the handler package nor its redis peer).
        $this->generateCacheHandler($projectDir);
        $this->installCacheDependencies($projectDir);

        // 7b. Scaffold Prisma for the chosen database engine.
        $this->scaffoldPrisma($projectDir, $database);

        // 8. Generate health check route
        $this->generateHealthRoute($projectDir);

        // 8b. Self-hosted values first, so the first render already works; a
        //     Commons join below replaces whatever it actually joined.
        $this->wireDatabaseEnv($config, $projectDir, $database);

        // 9. Generate K8s manifests
        $this->withSpin('Orchestrating Next.js infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config);
        });

        // 10. Join the Commons once the project exists, like every other scaffolder.
        if (! $this->option('no-plex')) {
            $this->joinPlexCommons($config, $projectDir);
            $this->wireCommonsDatabaseEnv($projectDir, $database);
        }

        $this->laraKubeInfo("✅ Next.js project '$appName' created successfully!");
        $this->newLine();
        $this->line('  <fg=gray>To start your Next.js application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        $this->line('  <fg=gray>Key configuration applied:</>');
        $this->line("  <fg=gray>  • output: 'standalone' — patched in next.config.ts</>");
        $this->line('  <fg=gray>  • Redis cache handler — cache-handler.mjs via @fortedigital/nextjs-cache-handler</>');
        $this->line('  <fg=gray>  • Health check route — app/api/health/route.ts</>');
        $this->line('  <fg=gray>  • Prisma migrations — run via K8s init container</>');
        $this->newLine();
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Scaffold the Next.js app inside Node via the shared ScaffoldsInNode trait,
     * so it runs under whichever container runtime is active (Docker or Podman)
     * and reuses the trait's TTY handling, host-user chown, and directory check.
     *
     * Interactive, create-next-app runs its own wizard — the same handoff
     * `larakube new` makes to `laravel new`. Only the choices the follow-up steps
     * depend on are pinned: the App Router and TypeScript at the project root, so
     * standalone patching and app/api/health/route.ts land where they're expected,
     * plus --no-git to avoid a nested repo. Tailwind, ESLint, the import alias and
     * Turbopack are the wizard's to ask. Scripted (no TTY, --fast,
     * --no-interaction) every option must be answered up front, so that line takes
     * the opinionated LaraKube defaults.
     */
    protected function runCreateNextApp(string $appName, string $baseDir): bool
    {
        $interactive = "npx --yes create-next-app@latest {$appName} --ts --app --no-src-dir --no-git";

        $scripted = "npx --yes create-next-app@latest {$appName}"
            .' --ts --tailwind --eslint --app --no-src-dir --import-alias "@/*" --no-git';

        return $this->scaffoldInNode($appName, $baseDir, 'Next.js app', $interactive, $scripted);
    }
}
