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
     * The Meet bridge URL wired by `meet:wire --tool=chat`, or null when Matrix
     * calling is not connected to a Meet install. Read back on every `chat:init`
     * so a re-run does not silently un-wire calling — same discipline as the
     * SMTP and OIDC read-backs either side of this.
     */
    protected function readChatWiredMeet(string $kubectl, string $ns): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'chat-meet', 'jwt-url');
    }

    /**
     * Replace Synapse's whole calling block in an already-rendered
     * homeserver.yaml. `meet:wire` edits the live config in place rather than
     * re-rendering the template, which needs inputs (db password, S3 keys) the
     * wire command has no business reading.
     *
     * This must stay in lockstep with the `@if($meetJwtUrl)` block in
     * resources/views/k8s/chat/matrix.blade.php — five top-level concerns, not
     * just the focus URL. Writing only the focus would point Element Call at a
     * working SFU while leaving MSC4140 off, which is the exact configuration
     * that made every client rejoin on a ~15s loop.
     *
     * Passing null strips the block, which is what unwire wants.
     */
    protected function renderSynapseCalling(string $rawYaml, ?string $meetJwtUrl): string
    {
        // Same strip-then-append shape as renderSynapseConfig(): drop each
        // top-level key and everything indented under it, then re-add.
        $yaml = $rawYaml;
        // 'well_known' is stripped but never written: it is NOT a Synapse option
        // and only ever sat inert in older renders. Dropping it here keeps a
        // re-wire from leaving a block that looks meaningful and is not.
        foreach (['experimental_features', 'well_known', 'extra_well_known_client_content', 'rc_message', 'rc_delayed_event_mgmt'] as $key) {
            $yaml = (string) preg_replace('/^[ \t]*'.$key.':\n(?:[ \t]+[^\n]*\n)*/m', '', $yaml);
        }
        $yaml = (string) preg_replace('/^[ \t]*max_event_delay_duration:[^\n]*\n/m', '', $yaml);
        $yaml = rtrim($yaml);

        if ($meetJwtUrl === null) {
            return $yaml."\n";
        }

        $yaml .= "\nexperimental_features:\n";
        $yaml .= "  msc3401_enabled: true\n";
        $yaml .= "  msc3266_enabled: true\n";
        $yaml .= "  msc4140_enabled: true\n";
        // msc4140_enabled without a delay ceiling makes Synapse reject every
        // delayed event, which reads to the client as "unsupported".
        $yaml .= "max_event_delay_duration: 24h\n";
        $yaml .= "rc_message:\n";
        $yaml .= "  per_second: 0.5\n";
        $yaml .= "  burst_count: 30\n";
        $yaml .= "rc_delayed_event_mgmt:\n";
        $yaml .= "  per_second: 1\n";
        $yaml .= "  burst_count: 20\n";
        // Must be `extra_well_known_client_content` — Synapse ignores unknown
        // top-level keys, so a `well_known:` block serves a focus-less
        // well-known and Element Call says the homeserver cannot call.
        $yaml .= "extra_well_known_client_content:\n";
        $yaml .= "  \"org.matrix.msc4143.rtc_foci\":\n";
        $yaml .= "    - type: livekit\n";
        $yaml .= '      livekit_service_url: "'.$meetJwtUrl."\"\n";

        return $yaml;
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
