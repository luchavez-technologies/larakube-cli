<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;

trait InteractsWithGitForge
{
    use ReadsClusterSecrets;

    /** The shared namespace Forgejo lives in. */
    protected function gitNamespace(): string
    {
        return ClusterTool::GIT->namespace();
    }

    /**
     * Forgejo Deployment present? With an instance, probes that exact
     * Deployment; without one, any Deployment carrying the git tool label.
     */
    protected function isGitInstalled(string $kubectl, string $ns, ?string $instance = null): bool
    {
        $target = $instance !== null
            ? 'deployment '.ClusterTool::GIT->deploymentName($instance)
            : 'deployment -l larakube.io/tool=git';

        return trim(Process::run("{$kubectl} get {$target} -n {$ns} --no-headers")->output()) !== '';
    }

    /**
     * Read-only Forgejo host for an env: local → git.{dev tld}; a cloud env →
     * the host persisted in .larakube.json (null when not configured yet). Never
     * prompts or persists.
     */
    protected function resolveGitHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::FORGEJO;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /**
     * Resolve Forgejo's access details for display.
     * Returns null when Forgejo isn't installed.
     *
     * @return array{host: ?string, label: string}|null
     */
    protected function gitAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->gitNamespace();

        $host = $this->resolveGitHostReadOnly($env, $config);
        $instance = $host !== null ? ClusterTool::GIT->instanceSlugFromHost($host) : null;

        if (! $this->isGitInstalled($kubectl, $ns, $instance)) {
            return null;
        }

        return [
            'host' => $host,
            'label' => 'Forgejo',
        ];
    }

    /**
     * Copy the registry credentials `git:init` minted (git-secrets-{instance},
     * the instance being the registry host's slug) into the project namespace
     * as the `forgejo-login` pull secret.
     */
    protected function ensureForgejoPullSecret(string $context, string $namespace, string $environment): void
    {
        $config = $this->getProjectConfigObject(getcwd());
        $registryHost = $config->getRegistry($environment)?->host;

        if (! $registryHost) {
            $this->laraKubeWarn("Skipped Forgejo pull secret — the environment's Forgejo registry has no host.");

            return;
        }

        $kubectl = Kubectl::forContext($context)->prefix();
        $sharedNs = $this->gitNamespace();
        $secret = ToolInstance::forHost(ClusterTool::GIT, $registryHost)->secret();

        $username = trim((string) $this->readClusterSecretKey($kubectl, $sharedNs, $secret, 'username'));
        $token = trim((string) $this->readClusterSecretKey($kubectl, $sharedNs, $secret, 'registry-token'));

        if ($username === '' || $token === '' || $token === 'pending') {
            $this->laraKubeWarn("Skipped Forgejo pull secret — could not read {$secret} credentials from {$sharedNs}.");

            return;
        }

        $ns = escapeshellarg($namespace);

        // Recreate the secret in the project namespace
        Process::run("{$kubectl} delete secret forgejo-login -n {$ns} --ignore-not-found");
        Process::run(
            "{$kubectl} create secret docker-registry forgejo-login -n {$ns} ".
            '--docker-server='.escapeshellarg($registryHost).' '.
            '--docker-username='.escapeshellarg($username).' '.
            '--docker-password='.escapeshellarg($token).' '.
            '--docker-email=admin@larakube.local',
        );
    }
}
