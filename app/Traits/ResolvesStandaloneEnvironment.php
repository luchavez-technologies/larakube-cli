<?php

namespace App\Traits;

use App\Services\Kubectl;

use function Laravel\Prompts\select;

trait ResolvesStandaloneEnvironment
{
    use InteractsWithClusterContext, InteractsWithProjectConfig, ResolvesEnvironmentContext;

    /**
     * @return array{0: string|null, 1: string} Returns [environment, kubectl]
     */
    protected function resolveStandaloneEnvironmentAndKubectl(): array
    {
        $explicitEnv = (string) ($this->argument('environment') ?: '');
        $explicitContext = (string) $this->option('context');

        $isProject = $this->isLaraKubeProject(false);
        $config = $isProject ? $this->getProjectConfig(getcwd()) : null;

        // 1. If context is provided, use it directly
        if ($explicitContext !== '') {
            return [$explicitEnv ?: null, Kubectl::forContext($explicitContext)->prefix()];
        }

        // 2. If environment is provided and we're in a project, map to context
        if ($explicitEnv !== '') {
            if ($config) {
                $context = $this->environmentContextOrCurrent($config, $explicitEnv);

                return [$explicitEnv, Kubectl::forContext($context)->prefix()];
            }
        }

        // 3. No explicit environment/context provided - time to prompt.
        if ($this->option('no-interaction') ?? false) {
            $context = $config ? $this->environmentContextOrCurrent($config, 'local') : null;

            return [$config ? 'local' : null, Kubectl::forContext($context)->prefix()];
        }

        if ($config) {
            $envs = array_merge(['local'], $config->getCloudEnvironments());
            $env = select(
                label: 'Which environment would you like to target?',
                options: array_combine($envs, $envs),
                default: 'local',
            );
            $context = $this->environmentContextOrCurrent($config, $env);

            return [$env, Kubectl::forContext($context)->prefix()];
        }

        // 4. Standalone Mode (No project) - Prompt for k8s context directly
        $contexts = $this->availableKubeContexts();
        if (empty($contexts)) {
            $this->laraKubeError('No Kubernetes contexts found. Is kubectl installed and configured?');

            return [null, Kubectl::forContext(null)->prefix()];
        }

        $currentContext = $this->currentKubeContext();

        $context = select(
            label: 'Which Kubernetes context would you like to target?',
            options: array_combine($contexts, $contexts),
            default: in_array($currentContext, $contexts, true) ? $currentContext : null,
        );

        return [null, Kubectl::forContext($context)->prefix()];
    }
}
