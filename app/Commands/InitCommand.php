<?php

namespace App\Commands;

use App\Contracts\HasArtisanCommands;
use App\Contracts\HasLifecycleHooks;
use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\PackageManager;
use App\Traits\CheckPrerequisites;
use App\Traits\DiffsProjectConfig;
use App\Traits\GathersInfrastructureConfig;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithArchitecturalEngine;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\PreparesNextjsProject;
use App\Traits\ScaffoldsInNode;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class InitCommand extends Command
{
    use CheckPrerequisites, DiffsProjectConfig, GathersInfrastructureConfig, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithDynamicOptions, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, PreparesNextjsProject, ScaffoldsInNode, StreamsProcessOutput;

    /** Same Node the Next.js scaffolder, dev pod and image build use. */
    protected const NODE_IMAGE = 'node:24-alpine';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'init {--fast : Skip the wizard and use ideal defaults}
                                 {--dry-run : Show what will be done without making any changes}
                                 {--framework= : The project\'s framework (skips detection and the picker)}
                                 {--no-plex : Next.js only: use a self-hosted database and Redis instead of joining Plex Commons}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize the LaraKube CLI for an existing project (Laravel, Statamic, WordPress, Next.js, Astro, Vite or Docusaurus)';

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();

        $isReinit = $this->isLaraKubeProject(false);
        $existingConfig = null;

        // 1. Nesting Protection & Reset Suggestion
        if ($isReinit) {
            $this->newLine();
            $this->warn(' ⚠ ALREADY INITIALIZED: This directory is already a LaraKube CLI project.');
            $this->line('   Running "init" again may conflict with your existing configuration.');
            $this->newLine();
            $this->info('   👉 BEST PRACTICE: If you want to start fresh, run "larakube reset" first.');
            $this->newLine();

            if (! confirm('Are you sure you want to proceed with re-initialization?')) {
                $this->laraKubeInfo('Initialization cancelled.');

                return 0;
            }

            $this->logActivity('Project re-initialization confirmed', ['action' => 'init'], getcwd());

            // Load the project's current DNA so the wizard below is pre-filled
            // with what's actually configured, instead of resetting to blank
            // defaults — and so we have a "before" snapshot to diff against.
            $existingConfig = $this->getProjectConfig(getcwd());
            if ($existingConfig === null) {
                return 1;
            }
        }

        $framework = $this->resolveInitFramework($existingConfig);
        if ($framework === null) {
            return 1;
        }

        if ($framework->isStaticSpa() || $framework === AppFramework::NEXTJS) {
            if (! $this->checkPrerequisites(false)) {
                return 1;
            }

            return $this->initNodeProject($framework, $existingConfig);
        }

        if (! $this->checkPrerequisites()) {
            return 1;
        }

        $before = $existingConfig ? clone $existingConfig : null;

        $config = $isReinit ? $this->buildConfigFromFlags($existingConfig) : $this->buildConfigFromFlags();
        $config->framework = $framework;
        $config->setIsScaffolding(false);
        $config = $this->gatherConfig($config, forcePrompts: $isReinit);
        $config->setPath(getcwd());

        $name = Str::slug(basename($config->getPath()));
        if ($name === 'console') {
            $this->laraKubeError('The directory name "console" is reserved for the LaraKube Console.');
            $this->line('  Please rename your directory or initialize in a different folder.');

            return 1;
        }

        $config->setName($name);

        if ($isReinit) {
            // Idempotent: preserves any previously-configured cloud environments
            // (production, staging, …), only ensures `local` exists.
            $config->addEnvironment('local');
        } else {
            // Environments are opt-in: a fresh project starts with `local` only.
            // Cloud environments (production, staging, …) are created on demand
            // via `larakube env` or `cloud:configure`.
            $config->setEnvironments(['local']);
        }

        $this->laraKubeInfo("Initializing LaraKube for project: {$config->getName()}...");

        $installFeatures = false;

        if (! empty($config->getFeatures())) {
            $installFeatures = confirm('Would you like to install the selected Laravel features now?');
        }

        if ($isReinit) {
            $diff = $this->diffConfigs($before, $config);
            $lines = $this->describeDiff($diff);

            if (empty($lines)) {
                $this->laraKubeInfo('No changes detected — your project already matches these settings.');

                return 0;
            }

            $this->laraKubeInfo('Architectural Preview: Changes to Apply');
            foreach ($lines as $line) {
                $this->line("  $line");
            }

            if ($this->option('dry-run')) {
                return 0;
            }

            if (! $this->option('fast') && ! $this->option('no-interaction')) {
                if (! confirm('Would you like to initialize LaraKube with these settings?')) {
                    $this->laraKubeInfo('Initialization cancelled.');

                    return 0;
                }
            }

            $this->replayDiff($diff, $config, $installFeatures);
        } else {
            // 1. Show Preview
            $this->orchestrateProjectScaffolding($config, $installFeatures, dryRun: true);

            if ($this->option('dry-run')) {
                return 0;
            }

            // 2. Confirm (Skip if --fast or --no-interaction)
            if (! $this->option('fast') && ! $this->option('no-interaction')) {
                if (! confirm('Would you like to initialize LaraKube with these settings?')) {
                    $this->laraKubeInfo('Initialization cancelled.');

                    return 0;
                }
            }

            $this->orchestrateProjectScaffolding($config, $installFeatures);
        }

        $this->laraKubeInfo("LaraKube initialized successfully for {$config->getName()}!");

        // Collect instructions from all components
        $allInstructions = [];
        foreach ($config->getComponents() as $component) {
            if ($component instanceof HasArtisanCommands && ! $config->isScaffolding) {
                foreach ($component->getArtisanCommands($config) as $cmd) {
                    $allInstructions[] = "Run: <fg=blue>larakube art $cmd</>";
                }
            }

            // Skip Commons-backed components: plex:join already created their
            // bucket and database, so the walkthrough would name ones the app never uses.
            if ($component instanceof HasLifecycleHooks && ! $config->isPlexBacked($component, 'local')) {
                $allInstructions = array_merge($allInstructions, $component->getPostInstallInstructions($config));
            }
        }

        if (! empty($allInstructions)) {
            $this->newLine();
            $this->warn('Perform these one-time architectural steps:');
            foreach ($allInstructions as $line) {
                $this->line("  $line");
            }
        }

        // Register with Console
        $this->registerWithConsole([
            'uuid' => $config->getId(),
            'name' => $config->getName(),
            'path' => $config->getPath(),
            'blueprints' => $config->getBlueprints(),
            'config' => $config->toArray(),
        ]);

        info('Next steps: larakube up');
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');

        return 0;
    }

    /**
     * The framework to initialize for: --framework, then (interactively) a
     * picker pre-selected with the detected framework, otherwise the detected
     * one. Only frameworks the LaraKube CLI can deploy are accepted.
     */
    protected function resolveInitFramework(?ConfigData $existing): ?AppFramework
    {
        $deployable = array_values(array_filter(AppFramework::cases(), fn (AppFramework $f) => $f->isDeployable()));
        $supported = implode(', ', array_map(fn (AppFramework $f) => $f->value, $deployable));

        $flag = trim((string) $this->option('framework'));
        if ($flag !== '') {
            $framework = AppFramework::tryFrom(strtolower($flag));
            if ($framework === null) {
                $this->laraKubeError("Unknown --framework '{$flag}'. Use one of: {$supported}.");

                return null;
            }

            return $this->refuseUndeployableFramework($framework) ? null : $framework;
        }

        $detected = $existing?->framework ?? AppFramework::detect((string) getcwd());

        if ($this->option('no-interaction') || $this->option('fast')) {
            if ($detected === null) {
                $this->laraKubeError('Could not detect this project\'s framework.');
                $this->line("   <fg=gray>Pass</> <fg=yellow>--framework=</><fg=gray>, one of: {$supported}.</>");

                return null;
            }

            return $this->refuseUndeployableFramework($detected) ? null : $detected;
        }

        if ($detected !== null && ! $detected->isDeployable()) {
            $this->laraKubeWarn("This looks like a {$detected->getLabel()} project, which the LaraKube CLI can't deploy yet.");
        }

        $options = [];
        foreach ($deployable as $framework) {
            $options[$framework->value] = $framework->getLabel().($framework === $detected ? ' (detected)' : '');
        }

        return AppFramework::from(select(
            label: 'Which framework is this project?',
            options: $options,
            default: $detected?->isDeployable() ? $detected->value : AppFramework::LARAVEL->value,
        ));
    }

    protected function refuseUndeployableFramework(AppFramework $framework): bool
    {
        if ($framework->isDeployable()) {
            return false;
        }

        $this->laraKubeError("{$framework->getLabel()} projects can't be deployed by the LaraKube CLI yet, so init can't adopt this one.");

        return true;
    }

    /**
     * Static sites and Next.js share none of the Laravel wizard: the blueprint
     * comes from the same builders their `:new` commands use, and Next.js gets
     * the project changes that make it deployable.
     */
    protected function initNodeProject(AppFramework $framework, ?ConfigData $existing): int
    {
        $path = (string) getcwd();

        if ($existing !== null) {
            $this->withSpin('Regenerating manifests from your blueprint...', function () use ($existing): void {
                $this->orchestrateProjectScaffolding($existing, installFeatures: false, buildImage: false);
            });
            $this->laraKubeInfo("LaraKube re-initialized for {$existing->getName()}.");

            return 0;
        }

        $name = Str::slug(basename($path));
        if ($name === 'console') {
            $this->laraKubeError('The directory name "console" is reserved for the LaraKube Console.');

            return 1;
        }

        $packageManager = PackageManager::detect($path);
        $drivers = null;

        if ($framework === AppFramework::NEXTJS) {
            $drivers = $this->gatherNextjsDrivers($this->option('fast') || $this->option('no-interaction'));
            $config = new ConfigData(id: $name, name: $name, path: $path, framework: $framework);
            $config->setEnvironments(['local']);
            $config->setPackageManager($packageManager);
            $config->setDatabase($drivers['database']);
            $config->setCacheDriver($drivers['cache']);
            if ($drivers['storage']) {
                $config->setObjectStorage($drivers['storage']);
            }
            if ($drivers['search']) {
                $config->setScoutDriver($drivers['search']);
            }
        } else {
            $config = ConfigData::forStaticSite($framework, $name, $path, $packageManager);
        }

        $config->setIsScaffolding(false);

        $this->laraKubeInfo("Initializing LaraKube for {$framework->getLabel()} project: {$name}...");
        $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false, dryRun: true);

        $changes = $drivers !== null ? $this->plannedNextjsChanges($path) : [];
        if ($changes !== []) {
            $this->newLine();
            $this->line('  <fg=gray>Project changes:</>');
            foreach ($changes as $change) {
                $this->line("  <fg=green>[ADD]</> {$change}");
            }
        }

        if ($this->option('dry-run')) {
            return 0;
        }

        if (! $this->option('fast') && ! $this->option('no-interaction')
            && ! confirm('Would you like to initialize LaraKube with these settings?')) {
            $this->laraKubeInfo('Initialization cancelled.');

            return 0;
        }

        if ($drivers !== null) {
            $this->prepareExistingNextjsProject($config, $path, $drivers['database']);
        }

        $this->saveProjectConfig($path, $config);
        $this->withSpin('Orchestrating infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false);
        });

        if ($drivers !== null && ! $this->option('no-plex')) {
            $this->joinPlexCommons($config, $path);
            $this->wireCommonsDatabaseEnv($path, $drivers['database']);
        }

        $this->registerWithConsole([
            'uuid' => $config->getId(),
            'name' => $config->getName(),
            'path' => $config->getPath(),
            'blueprints' => $config->getBlueprints(),
            'config' => $config->toArray(),
        ]);

        $this->laraKubeInfo("LaraKube initialized successfully for {$name}!");
        info('Next steps: larakube up');
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');

        return 0;
    }

    /**
     * Render a diff produced by diffConfigs() as human-readable [ADD]/[REMOVE]/
     * [SWAP] lines, RemoveCommand-style, for the re-init dry-run/confirm preview.
     *
     * @return array<int, string>
     */
    protected function describeDiff(array $diff): array
    {
        $lines = [];

        foreach ($diff['blueprints']['remove'] as $blueprint) {
            $lines[] = "<fg=red>[REMOVE]</> blueprint: {$blueprint->value}";
        }
        foreach ($diff['blueprints']['add'] as $blueprint) {
            $lines[] = "<fg=green>[ADD]</> blueprint: {$blueprint->value}";
        }

        foreach ($diff['features']['remove'] as $feature) {
            $lines[] = "<fg=red>[REMOVE]</> feature: {$feature->value}";
        }
        foreach ($diff['features']['add'] as $feature) {
            $lines[] = "<fg=green>[ADD]</> feature: {$feature->value}";
        }

        foreach (['database' => 'database', 'cache' => 'cache', 'storage' => 'storage', 'scout' => 'scout driver'] as $key => $label) {
            $lines = array_merge($lines, $this->describeScalarDiff($diff[$key], $label));
        }

        foreach (['phpVersion' => 'PHP version', 'os' => 'operating system', 'serverVariation' => 'server variation'] as $key => $label) {
            if (! $diff[$key]['changed']) {
                continue;
            }

            $new = $diff[$key]['new']->getLabel();
            $old = $diff[$key]['old']?->getLabel();

            $lines[] = $old
                ? "<fg=yellow>[SWAP]</> {$label}: {$old} → {$new}"
                : "<fg=green>[ADD]</> {$label}: {$new}";
        }

        return $lines;
    }

    /**
     * Configure the command to ignore validation errors so we can forward arbitrary flags.
     */
    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this->addArchitecturalOptions();
    }

    /**
     * @param  array{old: mixed, new: mixed, changed: bool}  $fieldDiff
     * @return array<int, string>
     */
    private function describeScalarDiff(array $fieldDiff, string $label): array
    {
        if (! $fieldDiff['changed']) {
            return [];
        }

        if ($fieldDiff['old'] === null) {
            return ["<fg=green>[ADD]</> {$label}: {$fieldDiff['new']->value}"];
        }

        if ($fieldDiff['new'] === null) {
            return ["<fg=red>[REMOVE]</> {$label}: {$fieldDiff['old']->value}"];
        }

        return ["<fg=yellow>[SWAP]</> {$label}: {$fieldDiff['old']->value} → {$fieldDiff['new']->value}"];
    }
}
