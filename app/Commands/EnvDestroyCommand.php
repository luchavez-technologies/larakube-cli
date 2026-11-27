<?php

namespace App\Commands;

use App\Services\PlexService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use InvalidArgumentException;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * Deletes a cloud environment completely, in the one order that leaves nothing behind:
 *
 *   1. `cloud:nuke`  — its namespace, workloads and volumes on its server;
 *   2. `plex:evict`  — its database, bucket and Redis index in Plex Commons, if it used them
 *                      (a backup of the database is kept next to the project first);
 *   3. `env:remove`  — its record in the blueprint and the files generated for it
 *                      (overlay manifests and deploy workflow).
 *
 * Each step needs what the previous ones still know (the server, the tenant), so the
 * blueprint is forgotten last and the command stops at the first failure: fix it and run
 * `env:destroy` again, every step is safe to repeat. To forget an environment without
 * touching its server, `env:remove` alone does that.
 */
class EnvDestroyCommand extends Command
{
    use ConfirmsDestructiveAction, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'env:destroy
        {environment? : The cloud environment to delete, with everything it runs}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a cloud environment completely: what it runs on its server, its Plex Commons data, and its blueprint entry and generated files';

    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = (string) getcwd();
        $config = $this->getProjectConfigObject($projectPath);
        $cloudEnvs = $config->getCloudEnvironments();

        if (empty($cloudEnvs)) {
            $this->laraKubeWarn('This project has no cloud environments to delete.');

            return 0;
        }

        $environment = $this->argument('environment');
        if ($environment === null) {
            if (isset($this->input) && ! $this->input->isInteractive()) {
                throw new InvalidArgumentException('Pass the environment to delete, e.g. larakube env:destroy staging.');
            }
            $environment = select(label: 'Which environment do you want to delete?', options: $cloudEnvs);
        }

        if ($environment === 'local') {
            $this->laraKubeError("'local' isn't a cloud environment. Use larakube down for local.");

            return 1;
        }

        if (! $config->hasEnvironment($environment)) {
            $this->laraKubeError("Environment '{$environment}' is not in this project's blueprint.");

            return 1;
        }

        $usesPlex = ! empty($config->getPlex($environment));
        $tenant = $usesPlex ? app(PlexService::class)->plexTenantIdentifier($config->getName(), $environment) : null;

        if (! $this->confirmDestructive(array_values(array_filter([
            "'{$environment}' will be DELETED, with everything it runs:",
            '  • its namespace, workloads and PERSISTENT DATA on its server (the server itself keeps running)',
            $usesPlex ? "  • its Plex Commons data (tenant {$tenant}), after a backup of its database" : null,
            '  • its entry in .larakube.json and its generated manifests and deploy workflow',
            "Its .env.{$environment} file is left on disk, and its CI secrets on your Git host.",
        ])))) {
            return 0;
        }

        $this->laraKubeInfo('1/'.($usesPlex ? 3 : 2)." Removing what '{$environment}' runs on its server...");
        if ((int) $this->call('cloud:nuke', ['environment' => $environment, '--force' => true]) !== 0) {
            return $this->stopped($environment, 'Its server could not be cleaned up.');
        }

        if ($usesPlex) {
            $this->laraKubeInfo('2/3 Removing its Plex Commons data...');
            if ((int) $this->call('plex:evict', ['environment' => $environment, '--tenant' => $tenant, '--force' => true]) !== 0) {
                return $this->stopped($environment, 'Its Plex Commons data could not be removed.');
            }
        }

        $this->laraKubeInfo(($usesPlex ? '3/3' : '2/2')." Removing '{$environment}' from the project...");
        if ((int) $this->call('env:remove', ['environment' => $environment, '--force' => true]) !== 0) {
            return $this->stopped($environment, 'It could not be removed from the blueprint.');
        }

        $this->newLine();
        $this->laraKubeInfo("✅ '{$environment}' is deleted.");
        $this->line("   <fg=gray>Left for you: .env.{$environment}, and the {$environment} CI secrets on your Git host if you set any.</>");

        return 0;
    }

    private function stopped(string $environment, string $reason): int
    {
        $this->laraKubeError("{$reason} Nothing after this step was done, and '{$environment}' is still in the blueprint.");
        $this->line("   <fg=gray>Fix it and run</> <fg=yellow>larakube env:destroy {$environment}</> <fg=gray>again; every step is safe to repeat.</>");
        $this->line("   <fg=gray>If its server is gone for good,</> <fg=yellow>larakube env:remove {$environment}</> <fg=gray>forgets it without touching anything live.</>");

        return 1;
    }
}
