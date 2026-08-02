<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

/**
 * Helpers for the Zitadel identity-provider tool. Mirrors InteractsWithDesk,
 * except its own dedicated `larakube-sso` namespace — same posture as
 * Vaultwarden/Infisical/NetBird: if this is compromised, everything
 * federated to it is compromised, so it doesn't share larakube-shared.
 */
trait InteractsWithSso
{
    use ResolvesEnvironmentContext;

    /** The namespace the SSO stack lives in — dedicated, not larakube-shared. */
    protected function ssoNamespace(): string
    {
        return 'larakube-sso';
    }

    /** Build the kubectl command, optionally scoped to a context, pinned to ~/.kube/config. */
    protected function ssoKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Zitadel Deployment present? */
    protected function isSsoInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment sso-zitadel -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    /** Read a key from the sso-secrets secret. */
    protected function readSsoSecret(string $kubectl, string $ns, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret sso-secrets -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    /** Read a key from any named secret in the SSO namespace (e.g. sso-app-drive). */
    protected function readNamedSecret(string $kubectl, string $ns, string $secret, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret {$secret} -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    /** Read-only Zitadel host for the given environment. */
    protected function resolveSsoHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::SSO;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /** Resolve Zitadel's access details for status output. */
    protected function ssoAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->ssoKubectl($context);
        $ns = $this->ssoNamespace();

        if (! $this->isSsoInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveSsoHostReadOnly($env, $config),
            'label' => 'Zitadel',
        ];
    }
}
