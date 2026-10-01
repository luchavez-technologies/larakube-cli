<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

/**
 * Helpers for the Stalwart mail server tool.
 */
trait InteractsWithMail
{
    // InteractsWithToolRegistry added for resolveMailInstance()'s
    // resolveLiveToolHost() call — not every class that already composes
    // InteractsWithMail also happens to compose InteractsWithToolRegistry
    // separately (confirmed live: several Mail command test doubles and
    // MailCheckCommand did not), so pull it in here rather than assume it.
    use InteractsWithToolRegistry, ManagesToolFirewallPorts, ReadsClusterSecrets, ResolvesEnvironmentContext;

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

    /**
     * Stalwart Deployment present? Found by its identity label, which avoids
     * every caller needing to resolve the instance before it can even find the
     * pod (the Deployment is named per instance).
     */
    protected function isMailInstalled(string $kubectl, string $ns): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeploymentLabelled($ns, 'larakube.io/tool=mail');
    }

    /**
     * Every Mail resource name (ADR 0021) for the instance this host serves.
     * $instance is optional: commands that read Mail state without knowing it
     * fall back to the live registered one. Null only when Mail is not deployed.
     */
    protected function mailNames(string $kubectl, ?string $instance = null): ?ToolInstance
    {
        $instance ??= $this->resolveMailInstance($kubectl);

        return $instance === '' ? null : ToolInstance::forInstance(ClusterTool::MAIL, $instance);
    }

    /**
     * MAIL's own resource-naming instance slug, resolved fresh from the tools
     * registry's currently-live host whenever a caller doesn't already have
     * it in hand — keeps every existing readMailSecret()/storeMailSecret()
     * call site correct without each one needing to independently resolve
     * $env/$host/$instance itself.
     * Callers that already computed it (MailInitCommand mid-deploy, before
     * it's even registered) should pass it explicitly instead.
     */
    protected function resolveMailInstance(string $kubectl): string
    {
        $host = $this->resolveLiveToolHost($kubectl, ClusterTool::MAIL);

        return ($host !== null && $host !== '') ? ClusterTool::MAIL->instanceSlugFromHost($host) : '';
    }

    /** Stalwart's credentials Secret, or the bare stem when no instance is registered yet. */
    protected function mailSecretsName(string $kubectl, ?string $instance = null): string
    {
        return $this->mailNames($kubectl, $instance)?->secret() ?? 'stalwart-secrets';
    }

    /** The cached relay credentials Secret (mail:relay). */
    protected function mailRelaySecretName(string $kubectl): string
    {
        return $this->mailNames($kubectl)?->name('relay') ?? 'stalwart-relay';
    }

    /** The cached sender credentials Secret (mail:wire). */
    protected function mailSenderSecretName(string $kubectl): string
    {
        return $this->mailNames($kubectl)?->name('sender') ?? 'stalwart-sender';
    }

    /** Read a key from Stalwart's credentials Secret. */
    protected function readMailSecret(string $kubectl, string $ns, string $key, ?string $instance = null): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, $this->mailSecretsName($kubectl, $instance), $key);
    }

    /** Read a key from the cached relay credentials Secret (mail:relay). */
    protected function readMailRelay(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, $this->mailRelaySecretName($kubectl), $key);
    }

    /** Read a key from the cached sender credentials Secret (mail:wire). */
    protected function readMailSender(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, $this->mailSenderSecretName($kubectl), $key);
    }

    /**
     * Write (or overwrite) a key on Stalwart's credentials Secret — a
     * plain k8s Secret patch. That Secret holds the mail server's OWN
     * credentials (recovery admin, admin password, automation api-key), which
     * are deliberately k8s-only and never synced to the secrets backend: the
     * mail server is foundational infrastructure that other tools depend on,
     * so its break-glass and automation credentials must stay self-contained
     * rather than gaining a dependency on the secrets manager. (Shared
     * secrets that OTHER systems consume — the Plex Commons store/S3 creds,
     * the mail:wire SMTP creds — do legitimately go to the secrets backend;
     * those are handled elsewhere.)
     */
    protected function storeMailSecret(string $kubectl, string $ns, string $key, string $value, ?string $instance = null): bool
    {
        return Kubectl::fromPrefix($kubectl)->patchSecret($ns, $this->mailSecretsName($kubectl, $instance), [$key => $value])->ok;
    }

    /** Read-only Stalwart host for the given environment. */
    protected function resolveMailHostReadOnly(string $env, ?ConfigData $config, ?string $kubectl = null): ?string
    {
        $service = SharedClusterService::MAIL;

        if ($kubectl !== null) {
            $registered = $this->resolveLiveToolHost($kubectl, ClusterTool::MAIL);
            if ($registered !== null && $registered !== '') {
                return $registered;
            }
        }

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
        $kubectl = Kubectl::forContext($context)->prefix();
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
