<?php

namespace App\Commands;

use App\Traits\ConfirmsDestructiveAction;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use InvalidArgumentException;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * The counterpart to `env` (which creates an environment overlay). Removes an
 * environment from this project's blueprint — `.larakube.json` and the
 * gitignored `.larakube.local.json` (cloud target/SSH details) — and deletes
 * what was generated for it: its `k8s/overlays/{env}` manifests (via the same
 * pruneStaleManifests() every `up`/`env`/`heal` runs) and its deploy workflow,
 * which would otherwise keep running on every push.
 *
 * Deliberately local-only: this never touches a live server or cluster.
 * `env:destroy` deletes a deployed environment completely and ends with this.
 */
class EnvRemoveCommand extends Command
{
    use ConfirmsDestructiveAction, GeneratesProjectInfrastructure, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'env:remove
        {environment? : The environment to remove from this project\'s blueprint}
        {--force : Skip the confirmation prompt}';

    protected $description = "Remove an environment from this project's blueprint — does not touch any live server or cluster";

    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);
        $cloudEnvs = $config->getCloudEnvironments();

        if (empty($cloudEnvs)) {
            $this->laraKubeWarn('This project has no cloud environments to remove.');

            return 0;
        }

        $environment = $this->argument('environment');

        if ($environment === null) {
            $interactive = ! isset($this->input) || $this->input->isInteractive();
            if (! $interactive) {
                throw new InvalidArgumentException('Pass the environment to remove, e.g. larakube env:remove production.');
            }

            $environment = select(
                label: 'Which environment do you want to remove?',
                options: $cloudEnvs,
            );
        }

        if ($environment === 'local') {
            $this->laraKubeError("'local' isn't a removable cloud environment.");

            return 1;
        }

        if (! $config->hasEnvironment($environment)) {
            $this->laraKubeError("Environment '{$environment}' is not in this project's blueprint.");

            return 1;
        }

        if (! $this->confirmDestructive([
            "'{$environment}' will be REMOVED from this project's blueprint.",
            'Its generated manifests and deploy workflow are deleted with it.',
            'This does NOT touch any live server or cluster — larakube env:destroy deletes those too.',
            "Its .env.{$environment} file is left on disk untouched.",
        ])) {
            return 0;
        }

        $config->removeEnvironment($environment);
        $this->saveProjectConfig($projectPath, $config);

        $pruned = [...$this->pruneStaleManifests($config), ...$this->removeDeployWorkflows((string) $projectPath, $environment)];
        foreach ($pruned as $path) {
            $this->line("  <fg=gray>Removed {$path}</>");
        }

        $this->laraKubeInfo("✅ '{$environment}' removed from this project's blueprint.");
        $this->line("   <fg=gray>.env.{$environment} was left in place.</>");

        return 0;
    }

    /**
     * The environment's generated deploy workflow on GitHub or Forgejo. GitLab keeps every
     * environment in one shared .gitlab-ci.yml, which is left for the next `cloud:configure`.
     *
     * @return list<string>
     */
    private function removeDeployWorkflows(string $projectPath, string $environment): array
    {
        $removed = [];
        foreach (['.github/workflows', '.forgejo/workflows'] as $dir) {
            $file = "{$dir}/larakube-deploy-{$environment}.yml";
            if (is_file("{$projectPath}/{$file}") && unlink("{$projectPath}/{$file}")) {
                $removed[] = $file;
            }
        }

        return $removed;
    }
}
