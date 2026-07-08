<?php

namespace App\Commands;

use App\Contracts\HasArtisanCommands;
use App\Contracts\HasLifecycleHooks;
use App\Traits\CheckPrerequisites;
use App\Traits\DiffsProjectConfig;
use App\Traits\GathersInfrastructureConfig;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithArchitecturalEngine;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class InitCommand extends Command
{
    use CheckPrerequisites, DiffsProjectConfig, GathersInfrastructureConfig, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithDynamicOptions, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'init {--fast : Skip the wizard and use ideal defaults}
                                 {--dry-run : Show what will be done without making any changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize LaraKube for an existing Laravel project';

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

        if (! $this->checkPrerequisites()) {
            return 1;
        }

        $before = $existingConfig ? clone $existingConfig : null;

        $config = $isReinit ? $this->buildConfigFromFlags($existingConfig) : $this->buildConfigFromFlags();
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

            if ($component instanceof HasLifecycleHooks) {
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
