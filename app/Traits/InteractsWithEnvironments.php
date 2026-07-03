<?php

namespace App\Traits;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

trait InteractsWithEnvironments
{
    /**
     * Get the available environments for this project. Sole source of truth
     * is `.larakube.json` — so if the user renames "production" to "main" or
     * adds a "qa" env, `larakube heal` is the only step needed; no command
     * code references hardcoded env names. Falls back to `local` only when no
     * project config exists yet (fresh init) — cloud environments are opt-in,
     * created via `larakube env`/`cloud:configure`, never assumed.
     */
    protected function getAvailableEnvironments(): array
    {
        $projectPath = getcwd();
        $config = method_exists($this, 'getProjectConfigObject')
            ? $this->getProjectConfigObject($projectPath)
            : null;

        $envs = $config?->getEnvironments() ?? [];

        return ! empty($envs) ? $envs : ['local'];
    }

    /**
     * Get the available environments excluding 'local'. Used by cloud
     * commands that, by definition, don't operate on the local cluster.
     */
    protected function getCloudEnvironments(): array
    {
        return array_values(array_filter(
            $this->getAvailableEnvironments(),
            fn (string $env) => $env !== 'local',
        ));
    }

    /**
     * Prompt the user to select an environment.
     */
    protected function askForEnvironment(string $label = 'Which environment would you like to target?', string $default = 'local'): string
    {
        return select(
            label: $label,
            options: $this->getAvailableEnvironments(),
            default: $default,
        );
    }

    /**
     * Prompt for a non-local environment. Used by cloud/gha commands where
     * targeting 'local' makes no sense. Auto-selects when only one cloud env
     * exists. Environments are opt-in, so a fresh project routinely has NONE
     * yet — that's the common case here, not a defensive edge case — so it
     * prompts for a brand-new name (suggesting "production") rather than
     * falling back to a picker that would only offer "local".
     */
    protected function askForCloudEnvironment(string $label = 'Which environment would you like to target?'): string
    {
        $envs = $this->getCloudEnvironments();

        if (count($envs) === 1) {
            return $envs[0];
        }

        if (empty($envs)) {
            return text(
                label: $label,
                placeholder: 'production',
                default: 'production',
                hint: 'No cloud environment exists yet — name the one you want to create.',
                required: true,
            );
        }

        return select(
            label: $label,
            options: $envs,
            default: $envs[0],
        );
    }

    /**
     * Get the Kubernetes namespace for a given environment.
     */
    protected function getNamespace(string $environment, ?string $appName = null): string
    {
        // Use current working directory if appName not provided
        $projectPath = getcwd();

        // Load config
        $config = method_exists($this, 'getProjectConfigObject')
            ? $this->getProjectConfigObject($projectPath)
            : null;

        $appName = $appName ?? $config?->getName() ?? basename($projectPath);

        return "$appName-$environment";
    }
}
