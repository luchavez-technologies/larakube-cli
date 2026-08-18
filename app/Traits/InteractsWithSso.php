<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

/**
 * Helpers for the Zitadel identity-provider tool. Mirrors InteractsWithDesk,
 * except its own dedicated `larakube-sso` namespace — same posture as
 * Vaultwarden/OpenBao/NetBird: if this is compromised, everything
 * federated to it is compromised, so it doesn't share larakube-shared.
 */
trait InteractsWithSso
{
    use InteractsWithToolRegistry, ReadsClusterSecrets, ResolvesEnvironmentContext;

    /** The namespace the SSO stack lives in — dedicated, not larakube-shared. */
    protected function ssoNamespace(): string
    {
        return ClusterTool::SSO->namespace();
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
        return $this->readClusterSecretKey($kubectl, $ns, 'sso-secrets', $key);
    }

    /**
     * Read-only Zitadel host for the given environment.
     *
     * $config is optional: several callers (the mail:* SSO-sync helpers) only
     * carry an $env, and passing null used to mean "no host" for every cloud
     * environment — which surfaced as a bogus "could not reach Zitadel's
     * automation credentials" even though the PAT was perfectly readable. Fall
     * back to the project config on disk so the answer depends on the
     * environment, not on which caller happened to thread the config through.
     */
    protected function resolveSsoHostReadOnly(string $env, ?ConfigData $config, ?string $kubectl = null): ?string
    {
        $service = SharedClusterService::SSO;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        if ($kubectl !== null) {
            $registered = $this->resolveLiveToolHost($kubectl, ClusterTool::SSO);
            if ($registered !== null && $registered !== '') {
                return $registered;
            }
        }

        $config ??= file_exists(getcwd().'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile(getcwd())
            : null;

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
            'host' => $this->resolveSsoHostReadOnly($env, $config, $kubectl),
            'label' => 'Zitadel',
        ];
    }

    /**
     * Find a Forgejo/Gitea OIDC login source by its ID.
     *
     * Looks up the canonical `zitadel` name first (the source name is baked
     * into the callback path, so it must match the redirect URI registered in
     * Zitadel), then falls back to the legacy `Login with SSO` label that
     * older wirings left behind. Returns null when no matching source exists —
     * for example when sso:wire ran on a tool that never wires OIDC this way.
     *
     * @param  string  $exec  Base `forgejo admin auth` command.
     */
    protected function findForgejoOidcSourceId(string $exec): ?string
    {
        $lines = array_filter(preg_split('/\R/', Process::run("{$exec} list")->output()) ?: []);

        $names = ['zitadel', 'Login with SSO'];
        foreach ($names as $name) {
            $pattern = '/^(\d+)\s+'.preg_quote($name, '/').'(?:\s|$)/';
            foreach ($lines as $line) {
                if (preg_match($pattern, trim($line), $m) === 1) {
                    return $m[1];
                }
            }
        }

        return null;
    }
}
