<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

/**
 * Helpers for the Stalwart mail server tool.
 */
trait InteractsWithMail
{
    use ManagesToolFirewallPorts, ResolvesEnvironmentContext;

    /**
     * Reverse the install-time port opening on teardown. A mail server that is
     * gone but whose SMTP ports are still open on the firewall is a real
     * exposure, so this runs from `mail:remove`.
     *
     * Thin alias over the generic helper — Stalwart was the first tool to need
     * raw L4 ports, Forgejo's SSH listener the second, so the mechanism lives on
     * ManagesToolFirewallPorts and every tool declares its ports on the enum.
     */
    protected function closeMailPorts(string $env): void
    {
        $this->closeToolPorts(SharedClusterService::MAIL, $env);
    }

    /** The namespace the mail stack lives in. */
    protected function mailNamespace(): string
    {
        return 'larakube-shared';
    }

    /** Build the kubectl command, optionally scoped to a context, pinned to ~/.kube/config. */
    protected function mailKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Stalwart Deployment present? */
    protected function isMailInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment stalwart -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    /** Read a key from the mail-secrets secret. */
    protected function readMailSecret(string $kubectl, string $ns, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret mail-secrets -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    /**
     * Write (or overwrite) a key on the mail-secrets secret — a plain k8s Secret
     * patch. mail-secrets holds the mail server's OWN credentials (recovery
     * admin, admin password, automation api-key), which are deliberately
     * k8s-only and never synced to the secrets backend: the mail server is foundational
     * infrastructure that other tools depend on, so its break-glass and
     * automation credentials must stay self-contained rather than gaining a
     * dependency on the secrets manager. (Shared secrets that OTHER systems
     * consume — the Plex Commons store/S3 creds, the mail:wire SMTP creds — do
     * legitimately go to the secrets backend; those are handled elsewhere.)
     */
    protected function storeMailSecret(string $kubectl, string $ns, string $key, string $value): bool
    {
        $patch = json_encode(['data' => [$key => base64_encode($value)]]);

        return Process::run(
            "{$kubectl} patch secret mail-secrets -n {$ns} --type=merge -p ".escapeshellarg((string) $patch),
        )->successful();
    }

    /** Read-only Stalwart host for the given environment. */
    protected function resolveMailHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::MAIL;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        $envData = $config?->getEnvironment($env);
        if ($envData === null) {
            return null;
        }

        return $envData->hosts[$service->value] ?? ($envData->domain ? $service->hostFor($envData->domain) : null);
    }

    /**
     * The SMTP submission endpoint tools should send through. Uses the public
     * mail host (not the in-cluster Service name) so Stalwart's TLS certificate
     * matches, on port 465 with implicit TLS — Stalwart's default submission
     * listener (it does not bind 587/STARTTLS out of the box), and the modern
     * recommended submission port (RFC 8314).
     *
     * @return array{host: string, port: string}
     */
    protected function mailSmtpEndpoint(string $host): array
    {
        return ['host' => $host, 'port' => '465'];
    }

    /** Resolve Stalwart's access details for status output. */
    protected function mailAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveMailHostReadOnly($env, $config),
            'label' => 'Stalwart',
        ];
    }
}
