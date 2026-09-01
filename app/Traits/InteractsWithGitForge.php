<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithGitForge
{
    use ReadsClusterSecrets;

    /** The shared namespace Forgejo lives in. */
    protected function gitNamespace(): string
    {
        return ClusterTool::GIT->namespace();
    }

    /** Build the kubectl command, optionally scoped to a specific context, pinned to ~/.kube/config. */
    protected function gitKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Forgejo Deployment present? A cheap "is Forgejo installed" probe. */
    protected function isGitInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment forgejo -n {$ns} --no-headers")->output();

        return trim($out) !== '';
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
        $kubectl = $this->gitKubectl($context);
        $ns = $this->gitNamespace();

        if (! $this->isGitInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveGitHostReadOnly($env, $config),
            'label' => 'Forgejo',
        ];
    }

    /**
     * Copy registry credentials from the shared `git-secrets` secret in the
     * `larakube-shared` namespace and create a local namespace-scoped pull-secret
     * named `forgejo-login` so project pods can pull private registry images.
     */
    protected function ensureForgejoPullSecret(string $context, string $namespace): void
    {
        $kubectl = $this->gitKubectl($context);
        $sharedNs = $this->gitNamespace();

        // Read username and registry-token from git-secrets secret
        $usernameRaw = Process::run("{$kubectl} get secret git-secrets -n {$sharedNs} -o jsonpath='{.data.username}'")->output();
        $tokenRaw = Process::run("{$kubectl} get secret git-secrets -n {$sharedNs} -o jsonpath='{.data.registry-token}'")->output();

        $username = trim((string) base64_decode(trim($usernameRaw)));
        $token = trim((string) base64_decode(trim($tokenRaw)));

        if ($username === '' || $token === '') {
            $this->laraKubeWarn('Skipped Forgejo pull secret — could not read git-secrets credentials from '.$sharedNs);

            return;
        }

        $config = $this->getProjectConfigObject(getcwd());
        $registry = $config->getRegistry($this->environmentContextName($namespace));
        $registryHost = $registry ? $registry->getRegistryHost() : 'git.dev.test';

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
