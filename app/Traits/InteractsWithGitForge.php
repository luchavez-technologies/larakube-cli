<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use App\Support\ForgejoRegistryLogin;
use Illuminate\Support\Facades\Process;

trait InteractsWithGitForge
{
    use ReadsClusterSecrets;

    /** The shared namespace Forgejo lives in. */
    protected function gitNamespace(): string
    {
        return ClusterTool::FORGEJO->namespace();
    }

    /**
     * Forgejo Deployment present? With an instance, probes that exact
     * Deployment; without one, any Deployment carrying the git tool label.
     */
    protected function isGitInstalled(string $kubectl, string $ns, ?string $instance = null): bool
    {
        $target = $instance !== null
            ? 'deployment '.ClusterTool::FORGEJO->deploymentName($instance)
            : 'deployment -l '.\App\Support\Labels::toolSelector(ClusterTool::FORGEJO);

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
        $instance = $host !== null ? ClusterTool::FORGEJO->instanceSlugFromHost($host) : null;

        if (! $this->isGitInstalled($kubectl, $ns, $instance)) {
            return null;
        }

        return [
            'host' => $host,
            'label' => 'Forgejo',
        ];
    }

    /**
     * Copy the registry credentials `tool:init --tool=forgejo` minted for the registry host, from
     * whichever managed server runs that Forgejo, into the project namespace as `forgejo-login`.
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
        $login = ForgejoRegistryLogin::find(
            $registryHost,
            $context,
            fn (string $kubectl, string $namespace, string $secret, string $key): ?string => $this->readClusterSecretKey($kubectl, $namespace, $secret, $key),
        );

        if ($login === null) {
            $secret = ToolInstance::forHost(ClusterTool::FORGEJO, $registryHost)->secret();
            $this->laraKubeWarn("Skipped Forgejo pull secret — no server LaraKube manages has {$secret} for {$registryHost}.");

            return;
        }

        ['username' => $username, 'token' => $token] = $login;

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
