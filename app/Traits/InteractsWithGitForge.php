<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithGitForge
{
    /** The shared namespace Gitea lives in. */
    protected function gitNamespace(): string
    {
        return 'larakube-shared';
    }

    /** Build the kubectl command, optionally scoped to a specific context, pinned to ~/.kube/config. */
    protected function gitKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Gitea Deployment present? A cheap "is Gitea installed" probe. */
    protected function isGitInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment gitea -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /**
     * Read-only Gitea host for an env: local → git.{dev tld}; a cloud env →
     * the host persisted in .larakube.json (null when not configured yet). Never
     * prompts or persists.
     */
    protected function resolveGitHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::GITEA;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /**
     * Resolve Gitea's access details for display.
     * Returns null when Gitea isn't installed.
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
            'label' => 'Gitea',
        ];
    }

    /**
     * Copy registry credentials from the shared `gitea-admin` secret in the
     * `larakube-shared` namespace and create a local namespace-scoped pull-secret
     * named `gitea-login` so project pods can pull private registry images.
     */
    protected function ensureGiteaPullSecret(string $context, string $namespace): void
    {
        $kubectl = $this->gitKubectl($context);
        $sharedNs = $this->gitNamespace();

        // Read username and registry-token from gitea-admin secret
        $usernameRaw = Process::run("{$kubectl} get secret gitea-admin -n {$sharedNs} -o jsonpath='{.data.username}'")->output();
        $tokenRaw = Process::run("{$kubectl} get secret gitea-admin -n {$sharedNs} -o jsonpath='{.data.registry-token}'")->output();

        $username = trim((string) base64_decode(trim($usernameRaw)));
        $token = trim((string) base64_decode(trim($tokenRaw)));

        if ($username === '' || $token === '') {
            $this->laraKubeWarn('Skipped Gitea pull secret — could not read gitea-admin credentials from '.$sharedNs);

            return;
        }

        $config = $this->getProjectConfigObject(getcwd());
        $registry = $config->getRegistry($this->environmentContextName($namespace));
        $registryHost = $registry ? $registry->getRegistryHost() : 'git.dev.test';

        $ns = escapeshellarg($namespace);

        // Recreate the secret in the project namespace
        Process::run("{$kubectl} delete secret gitea-login -n {$ns} --ignore-not-found");
        Process::run(
            "{$kubectl} create secret docker-registry gitea-login -n {$ns} ".
            '--docker-server='.escapeshellarg($registryHost).' '.
            '--docker-username='.escapeshellarg($username).' '.
            '--docker-password='.escapeshellarg($token).' '.
            '--docker-email=admin@larakube.local',
        );
    }
}
