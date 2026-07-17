<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

/**
 * Helpers for the Stalwart mail server tool. Mirrors InteractsWithSheet, minus
 * the Plex Commons bits: Stalwart is self-contained (embedded RocksDB store on
 * a PVC), so it never allocates a Commons database.
 */
trait InteractsWithMail
{
    use ResolvesEnvironmentContext;

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

    /** Stalwart StatefulSet present? */
    protected function isMailInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get statefulset stalwart -n {$ns} --no-headers --ignore-not-found")->output();

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

    /** Read-only Stalwart host for the given environment. */
    protected function resolveMailHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::MAIL;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
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
