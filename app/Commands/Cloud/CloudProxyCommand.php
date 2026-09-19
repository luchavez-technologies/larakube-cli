<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Services\Kubectl;
use App\Traits\ChecksCloudflareProxy;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCloudProxy;
use App\Traits\ManagesTraefikAcmeChallenge;
use App\Traits\ReadsClusterSecrets;
use App\Traits\ReadsStoredCloudflareTokens;
use App\Traits\ResolvesEnvironmentContext;
use LaravelZero\Framework\Commands\Command;

/**
 * Route an environment's app hosts through the Cloudflare proxy (orange
 * cloud), after checking nothing about the cluster would break once it is.
 */
class CloudProxyCommand extends Command
{
    use ChecksCloudflareProxy, GeneratesProjectInfrastructure, InteractsWithProjectConfig,
        LaraKubeOutput, ManagesCloudProxy, ManagesTraefikAcmeChallenge, ReadsClusterSecrets, ReadsStoredCloudflareTokens,
        ResolvesEnvironmentContext;

    protected $signature = 'cloud:proxy
        {environment : The cloud environment whose hosts go through the Cloudflare proxy}';

    protected $description = 'Route an environment\'s hosts through the Cloudflare proxy (CDN, DDoS protection, hidden server IP)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = (string) getcwd();
        $config = $this->getProjectConfig($projectPath);

        if ($config === null) {
            $this->laraKubeError('Run this inside a LaraKube CLI project.');

            return 1;
        }

        if ($env === 'local' || $config->getEnvironment($env) === null) {
            $this->laraKubeError("'{$env}' isn't a cloud environment of this project.");

            return 1;
        }

        if ($config->isProxied($env)) {
            $this->laraKubeInfo("'{$env}' is already proxied through Cloudflare.");

            return 0;
        }

        $hosts = $this->proxyableHosts($config, $env);
        if ($hosts === []) {
            $this->laraKubeError("'{$env}' has no public host yet. Set one with `larakube cloud:configure {$env}`.");

            return 1;
        }

        [$config, $context] = $this->resolveEnvironmentContext($config, $env, $projectPath);
        $kubectl = Kubectl::forContext($context)->prefix();

        if (! $this->proxyChecksPass($env, $kubectl, $hosts, $this->usesTraefikCertificates($config, $env))) {
            return 1;
        }

        $this->applyProxySetting($config, $env, $projectPath, true);

        return 0;
    }

    /**
     * Whether Traefik's ACME issues this environment's certificates; when it
     * doesn't, the origin certificate is the operator's to keep valid.
     */
    protected function usesTraefikCertificates(ConfigData $config, string $env): bool
    {
        $usesTraefikAcme = $config->getIngress($env)->getAnnotationView() === null
            && ! ($config->getEnvironment($env)?->offline ?? false);

        if (! $usesTraefikAcme) {
            $this->laraKubeWarn("'{$env}' doesn't get certificates from Traefik. Make sure its origin certificate is one Cloudflare's Full (strict) mode accepts.");
        }

        return $usesTraefikAcme;
    }
}
