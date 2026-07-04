<?php

namespace App\Commands;

use App\Traits\GathersEnvironmentData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\PromptsForHosts;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class EnvCommand extends Command
{
    use GathersEnvironmentData, GeneratesProjectInfrastructure, InteractsWithProjectConfig, LaraKubeOutput, PromptsForHosts;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'env {name? : The name of the new environment}
                            {--offline : Mark this environment for air-gapped / offline distribution}
                            {--edit : Re-run the ingress/managed-services/hosts wizard on an existing environment, prefilled with its current settings}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Kubernetes environment overlay';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);

        $envName = $this->argument('name') ?? text(
            label: 'What is the name of the new environment?',
            placeholder: 'staging',
            required: true,
        );

        // 1. Per-environment .env file (seeded from the base .env).
        $newEnvFile = ".env.{$envName}";
        if (! file_exists($projectPath.'/'.$newEnvFile) && file_exists($projectPath.'/.env')) {
            copy($projectPath.'/.env', $projectPath.'/'.$newEnvFile);
            $this->laraKubeInfo("Created {$newEnvFile}");
        }

        // 2. Keep .env.* out of version control.
        $gitignorePath = $projectPath.'/.gitignore';
        if (file_exists($gitignorePath)) {
            $gitignore = file_get_contents($gitignorePath);
            if (! str_contains($gitignore, '.env.*')) {
                file_put_contents($gitignorePath, $gitignore."\n.env.*\n");
                $this->laraKubeInfo('Updated .gitignore to exclude .env.* files');
            }
        }

        // 3. Update Project DNA — gather per-env settings if this is a fresh env,
        // or re-gather (prefilled with current values) if --edit was passed for
        // review/editing an existing one. Assigns individual fields rather than
        // replacing the whole EnvironmentData object, so untouched settings this
        // wizard doesn't cover (cloud target, resources, tunnel, …) survive intact.
        if (! $config->hasEnvironment($envName)) {
            $this->laraKubeInfo("Creating environment '{$envName}'...");
            $envData = $this->gatherEnvironmentData($config, $envName);
            if ($this->option('offline')) {
                $envData->offline = true;
            }
            $config->addEnvironment($envName, $envData);
        } elseif ($this->option('edit')) {
            $this->laraKubeInfo("Editing environment '{$envName}'...");
            $fresh = $this->gatherEnvironmentData($config, $envName);
            $existing = $config->environments[$envName];
            $existing->ingress = $fresh->ingress;
            $existing->managed = $fresh->managed;
            $existing->hosts = $fresh->hosts;
            $existing->additionalWebHosts = $fresh->additionalWebHosts;
            if ($fresh->registry !== null) {
                $existing->registry = $fresh->registry;
            }
        } else {
            $this->laraKubeInfo("Environment '{$envName}' already exists in DNA; keeping current settings. Pass --edit to review and update it.");
        }
        $this->saveProjectConfig($projectPath, $config);

        // 4. Regenerate manifests from the blueprint. The architectural engine
        // is environment-aware, so this produces a complete overlays/{$envName}
        // reflecting THIS env's ingress, hosts, managed services, and feature
        // set — not a copy of production.
        $this->withSpin("Generating manifests for '{$envName}' (and refreshing all environments)...", function () use ($config) {
            $this->orchestrateProjectScaffolding($config, false, false);

            return true;
        });

        $this->laraKubeInfo("Environment '{$envName}' is now part of your project DNA.");
        $this->newLine();

        $isOffline = $config->environments[$envName]->offline ?? false;

        // 5. Offline environments don't need CI/CD — they deploy via bundles.
        //    For online environments, offer to set up the CI workflow (GitHub
        //    Actions or GitLab CI, auto-detected from the git remote).
        if (! $isOffline) {
            $configureCicd = confirm(
                label: "Set up the CI/CD deploy workflow for '{$envName}' now?",
                default: false,
                hint: 'Generates the deploy workflow (you pick its trigger branch) and uploads secrets.',
            );

            if ($configureCicd) {
                $this->call('cloud:configure', ['environment' => $envName, '--only' => 'ci']);

                return;
            }
        }

        $this->line('  <fg=gray>Next steps:</>');
        $this->line("  1. Preview the merged manifests:  <fg=yellow>larakube kustomize {$envName}</>");
        if ($isOffline) {
            $this->line("  2. Build the air-gapped bundle:   <fg=yellow>larakube bundle:build {$envName} --tar</>");
        } else {
            $this->line("  2. Set up CI/CD (per-env workflow + branch):  <fg=yellow>larakube cloud:configure {$envName} --only=ci</>");
            $this->line("  3. Or deploy manually:  <fg=yellow>larakube cloud:deploy {$envName}</>");
        }
    }
}
