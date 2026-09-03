<?php

namespace App\Commands\Vite;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\PackageManager;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ScaffoldsInNode;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class ViteNewCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, ScaffoldsInNode, StreamsProcessOutput;

    /** Node LTS at time of writing; 26 goes LTS in October 2026. */
    protected const NODE_IMAGE = 'node:24-alpine';

    /**
     * What a scripted run gets when nobody can answer create-vite's wizard.
     * Only reached with --fast, --no-interaction, or no TTY at all.
     */
    protected const SCRIPTED_TEMPLATE = 'react-ts';

    protected $signature = 'vite:new
        {name? : The name of the Vite application}
        {--template= : Pass a create-vite template through (skips its wizard)}
        {--pm=npm : Package manager (npm, pnpm, bun, yarn)}
        {--fast : Skip create-vite\'s wizard and take the react-ts template}';

    protected $description = 'Scaffold a new Vite SPA (React, Vue, Svelte, Solid) with LaraKube infrastructure';

    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();

        if (! $this->checkPrerequisites(false)) {
            return 1;
        }

        $inputName = $this->argument('name') ?? text(
            label: 'What is the name of your Vite application?',
            placeholder: 'my-vite-app',
            required: true,
            validate: fn (string $value) => strtolower($value) === 'console'
                ? 'The name "console" is reserved for the LaraKube Console.'
                : null,
        );

        $appName = Str::slug($inputName);
        $projectDir = "{$projectPath}/{$appName}";

        if (is_dir($projectDir)) {
            $this->laraKubeError("Directory {$appName} already exists.");

            return 1;
        }

        $packageManager = PackageManager::tryFrom((string) $this->option('pm')) ?? PackageManager::NPM;

        if (! $this->runCreateVite($appName, $projectPath)) {
            $this->laraKubeError('Vite scaffolding failed.');

            return 1;
        }

        $config = new ConfigData(
            id: $appName,
            name: $appName,
            path: $projectDir,
            framework: AppFramework::VITE,
            frontend: null,
        );
        $config->setIsScaffolding(true);
        $config->setName($appName);
        $config->setPath($projectDir);
        // Cloud environments are opt-in — `larakube env production` adds one.
        $config->setEnvironments(['local']);
        $config->setPackageManager($packageManager);
        // Default watchPaths are Laravel's (app/, bootstrap/, routes/,
        // composer.lock, .env) — none of which exist here.
        $config->watchPaths = ['src', 'public', 'index.html', 'package.json', 'vite.config.js', 'vite.config.ts'];

        // No PocketBase/Directus variables are seeded. A landing page has no
        // backend, and `larakube data:wire` exists to add them on demand — a
        // scaffold must never imply a service the cluster may not be running.
        $this->withSpin('Orchestrating infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false);
        });

        $host = $config->getWebHost('local');

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Created Vite app in {$appName}/");
        $this->newLine();
        $this->line('  <fg=gray>Start it with hot module replacement:</>');
        $this->line("  <fg=yellow>cd {$appName} && larakube up</>");
        $this->line("  <fg=gray>Then open</> <fg=blue>https://{$host}</>");
        $this->newLine();
        $this->line('  <fg=gray>Optional — connect a backend:</>  <fg=yellow>larakube data:wire</>');
        $this->line('  <fg=gray>Ready to deploy?</>                <fg=yellow>larakube env production</>');
        $this->newLine();
        $this->renderStarPrompt();

        return 0;
    }

    /**
     * Run create-vite inside Node rather than on the host, so the toolchain is
     * the same one the dev pod uses and the machine needs no local Node.
     */
    protected function runCreateVite(string $appName, string $baseDir): bool
    {
        $template = $this->option('template');

        // Interactive: no --template, so create-vite runs its own framework and
        // variant wizard — the one Vite keeps current. Scripted: a template is
        // required, because with no terminal the wizard cannot be answered.
        return $this->scaffoldInNode(
            $appName,
            $baseDir,
            'Vite app',
            "npx --yes create-vite@latest {$appName}".($template !== null ? ' --template '.escapeshellarg((string) $template) : ''),
            "npx --yes create-vite@latest {$appName} --template ".escapeshellarg((string) ($template ?? self::SCRIPTED_TEMPLATE)),
        );
    }
}
