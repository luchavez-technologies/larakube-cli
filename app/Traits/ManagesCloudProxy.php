<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\LaravelFeature;

/**
 * Shared by `cloud:proxy` and `cloud:unproxy`: which hosts an environment
 * serves, and how a changed proxy setting reaches the cluster.
 *
 * The using class provides InteractsWithProjectConfig, GeneratesProjectInfrastructure
 * and LaraKubeOutput.
 */
trait ManagesCloudProxy
{
    /**
     * Every public host this environment's app Ingresses serve.
     *
     * @return list<string>
     */
    protected function proxyableHosts(ConfigData $config, string $environment): array
    {
        $hosts = $config->getWebHosts($environment);

        if ($config->hasFeature(LaravelFeature::REVERB, $environment) && ($reverb = $config->getHost($environment, 'reverb'))) {
            $hosts[] = $reverb;
        }

        return array_values(array_unique($hosts));
    }

    /** Save the setting, regenerate the manifests, and say how it goes live. */
    protected function applyProxySetting(ConfigData $config, string $environment, string $projectPath, bool $proxied): void
    {
        $config->setProxied($environment, $proxied);
        $this->saveProjectConfig($projectPath, $config);

        $this->withSpin("Regenerating the {$environment} manifests...", function () use ($config): void {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false, syncEnv: false);
        });

        $this->newLine();
        $state = $proxied ? 'proxied through Cloudflare' : 'DNS-only';
        $this->laraKubeInfo("✅ '{$environment}' is now set to {$state}. It takes effect on the next deploy:");

        if (is_file("{$projectPath}/.github/workflows/larakube-deploy-{$environment}.yml") || is_file("{$projectPath}/.gitlab-ci.yml")) {
            $this->line('  <fg=gray>Commit</> <fg=blue>.larakube.json .infrastructure</> <fg=gray>and push. CI deploys it.</>');
        } else {
            $this->line("  <fg=blue>larakube cloud:deploy {$environment}</>");
        }

        $this->line('  <fg=gray>ExternalDNS then updates the Cloudflare record within a minute.</>');
        $this->newLine();
    }
}
