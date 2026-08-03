<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

/**
 * Helpers for the Team Chat tool (Matrix / Synapse + Cinny).
 */
trait InteractsWithChat
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    /** The namespace the chat stack lives in. */
    protected function chatNamespace(): string
    {
        return 'larakube-shared';
    }

    /** Build the kubectl command, optionally scoped to a context, pinned to ~/.kube/config. */
    protected function chatKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Chat Deployment present (Synapse)? */
    protected function isChatInstalled(string $kubectl, string $ns): bool
    {
        return trim(Process::run("{$kubectl} get deployment chat-synapse -n {$ns} --no-headers --ignore-not-found")->output()) !== '';
    }

    /** Which engine is installed? Always returns 'matrix' when present. */
    protected function chatEngineInstalled(string $kubectl, string $ns): ?string
    {
        return $this->isChatInstalled($kubectl, $ns) ? 'matrix' : null;
    }

    /** Read a key from the chat-secrets secret. */
    protected function readChatSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'chat-secrets', $key);
    }

    /**
     * Read wired SMTP values from the `chat-smtp` Secret.
     *
     * Returns an array suitable for passing as `$smtp` to the matrix view, or
     * null when the Secret does not exist (unwired state). The six keys map
     * directly to Synapse's `email:` block in homeserver.yaml.
     *
     * @return array{host: string, port: string, user: string, password: string, from: string}|null
     */
    protected function readChatWiredSmtp(string $kubectl, string $ns): ?array
    {
        $read = function (string $key) use ($kubectl, $ns): ?string {
            $out = trim(Process::run(
                "{$kubectl} get secret chat-smtp -n {$ns} -o jsonpath='{.data.{$key}}'",
            )->output());

            return $out !== '' ? (string) base64_decode($out) : null;
        };

        $host = $read('host');
        if ($host === null) {
            return null;
        }

        return [
            'host' => $host,
            'port' => $read('port') ?? '465',
            'user' => $read('user') ?? '',
            'password' => $read('password') ?? '',
            'from' => $read('from') ?? '',
        ];
    }

    /**
     * Read wired OIDC values from the `chat-oidc` Secret.
     *
     * Returns an array suitable for passing as `$oidc` to the matrix view, or
     * null when the Secret does not exist (unwired state).
     *
     * @return array{issuer: string, client_id: string, client_secret: string, name: string}|null
     */
    protected function readChatWiredOidc(string $kubectl, string $ns): ?array
    {
        $read = function (string $key) use ($kubectl, $ns): ?string {
            $out = trim(Process::run(
                "{$kubectl} get secret chat-oidc -n {$ns} -o jsonpath='{.data.{$key}}'",
            )->output());

            return $out !== '' ? (string) base64_decode($out) : null;
        };

        $issuer = $read('issuer');
        if ($issuer === null) {
            return null;
        }

        return [
            'issuer' => $issuer,
            'client_id' => $read('client-id') ?? '',
            'client_secret' => $read('client-secret') ?? '',
            'name' => $read('name') ?? 'Zitadel',
        ];
    }

    /**
     * Re-render a raw homeserver.yaml string with updated optional email and
     * oidc_providers blocks.
     *
     * @param  array{host: string, port: string, user: string, password: string, from: string}|null  $smtp
     * @param  array{issuer: string, client_id: string, client_secret: string, name: string}|null  $oidc
     */
    protected function renderSynapseConfig(string $rawYaml, ?array $smtp, ?array $oidc): string
    {
        // Strip any existing email: and oidc_providers: blocks so we can
        // re-render cleanly (handles both 0-indent and malformed 4-indent blocks).
        $yaml = (string) preg_replace('/^[ \t]*email:\n(?:[ \t]+[^\n]*\n)*/m', '', $rawYaml);
        $yaml = (string) preg_replace('/^[ \t]*oidc_providers:\n(?:[ \t]+[^\n]*\n)*/m', '', $yaml);
        $yaml = rtrim($yaml);

        if ($smtp !== null) {
            $yaml .= "\nemail:\n";
            $yaml .= "  enable_notifs: true\n";
            $yaml .= '  notif_from: "'.$smtp['from']."\"\n";
            $yaml .= '  smtp_host: "'.$smtp['host']."\"\n";
            $yaml .= '  smtp_port: '.((int) $smtp['port'])."\n";
            $yaml .= '  smtp_user: "'.$smtp['user']."\"\n";
            $yaml .= '  smtp_pass: "'.$smtp['password']."\"\n";
        }

        if ($oidc !== null) {
            $yaml .= "oidc_providers:\n";
            $yaml .= "  - idp_id: zitadel\n";
            $yaml .= '    idp_name: "'.$oidc['name']."\"\n";
            $yaml .= "    discover: true\n";
            $yaml .= '    issuer: "'.$oidc['issuer']."\"\n";
            $yaml .= '    client_id: "'.$oidc['client_id']."\"\n";
            $yaml .= '    client_secret: "'.$oidc['client_secret']."\"\n";
            $yaml .= "    scopes: [\"openid\", \"profile\", \"email\"]\n";
            $yaml .= "    allow_existing_users: true\n";
            $yaml .= "    user_mapping_provider:\n";
            $yaml .= "      config:\n";
            $yaml .= "        localpart_template: \"{{ user.preferred_username }}\"\n";
            $yaml .= "        display_name_template: \"{{ user.name }}\"\n";
            $yaml .= "        email_template: \"{{ user.email }}\"\n";
        }

        return $yaml."\n";
    }

    /** Read-only Chat host for the given environment. */
    protected function resolveChatHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::CHAT;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /** Resolve Chat access details for status output. */
    protected function chatAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->chatKubectl($context);
        $ns = $this->chatNamespace();

        $engine = $this->chatEngineInstalled($kubectl, $ns);
        if (! $engine) {
            return null;
        }

        $label = 'Matrix (Synapse + Element)';

        return [
            'host' => $this->resolveChatHostReadOnly($env, $config),
            'label' => $label,
        ];
    }
}
