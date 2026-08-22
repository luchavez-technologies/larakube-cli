<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

/**
 * Helpers for the Team Chat tool (Matrix / Synapse + Element Web).
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
     * Write (or overwrite) a key on the chat-secrets secret — a plain k8s
     * Secret patch, same posture as InteractsWithMail::storeMailSecret():
     * this holds Synapse's OWN self-contained automation credentials (the
     * larakube-automation admin's access token/password, minted lazily by
     * InteractsWithMatrixApi::matrixAdminToken()), not something other
     * tools consume, so it stays k8s-only rather than gaining an OpenBao
     * dependency.
     */
    protected function storeChatSecret(string $kubectl, string $ns, string $key, string $value): bool
    {
        $patch = json_encode(['data' => [$key => base64_encode($value)]]);

        return Process::run(
            "{$kubectl} patch secret chat-secrets -n {$ns} --type=merge -p ".escapeshellarg((string) $patch),
        )->successful();
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
     * Passing null for either argument strips just that argument's sub-key —
     * the two concerns share the single `extra_well_known_client_content:`
     * top-level key (Synapse ignores unknown top-level keys, so they cannot
     * each own a separate one), so this method is the SOLE owner of that key
     * and every caller (meet:wire/unwire, ChatInitCommand's own
     * activateMasAuthMode()) must read back and pass through the OTHER
     * concern's current state or it
     * will silently clobber it — same discipline as renderSynapseConfig()'s
     * email/oidc juggling.
     */
    protected function renderSynapseCalling(string $rawYaml, ?string $meetJwtUrl, ?string $masPublicIssuer = null): string
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
        $yaml = rtrim($yaml)."\n";

        // Rendered via Blade, not string-built here: Blade's own line
        // structure makes the "does this block need a leading newline"
        // bookkeeping a non-issue by construction — the exact class of bug
        // the hand-built version of this had (missing newline between the
        // stripped YAML and whichever block ran first).
        return $yaml.view('k8s.chat.partials.calling-block', [
            'meetJwtUrl' => $meetJwtUrl,
            'masPublicIssuer' => $masPublicIssuer,
        ])->render();
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
            'name' => $read('name') ?? 'Login with SSO',
        ];
    }

    /**
     * Re-render a raw homeserver.yaml string with updated optional email and
     * auth-delegation blocks. `$mas` and `$oidc` are mutually exclusive —
     * Synapse cannot run classic oidc_providers: and MAS-delegated auth
     * (matrix_authentication_service:) at the same time for the same users.
     * When both are somehow present (should never happen — $oidc's presence
     * is what gates ChatInitCommand from ever activating $mas in the first
     * place), `$mas` wins, since it represents the more recently activated
     * state.
     *
     * @param  array{host: string, port: string, user: string, password: string, from: string}|null  $smtp
     * @param  array{issuer: string, client_id: string, client_secret: string, name: string}|null  $oidc
     * @param  array{endpoint: string, secret: string}|null  $mas
     */
    protected function renderSynapseConfig(string $rawYaml, ?array $smtp, ?array $oidc, ?array $mas = null): string
    {
        // Strip any existing email:, oidc_providers:, and
        // matrix_authentication_service: blocks so we can re-render cleanly
        // (handles both 0-indent and malformed 4-indent blocks).
        $yaml = (string) preg_replace('/^[ \t]*email:\n(?:[ \t]+[^\n]*\n)*/m', '', $rawYaml);
        $yaml = (string) preg_replace('/^[ \t]*oidc_providers:\n(?:[ \t]+[^\n]*\n)*/m', '', $yaml);
        $yaml = (string) preg_replace('/^[ \t]*matrix_authentication_service:\n(?:[ \t]+[^\n]*\n)*/m', '', $yaml);
        $yaml = rtrim($yaml)."\n";

        // Rendered via Blade, not string-built here — see renderSynapseCalling()'s
        // comment for why: it removes the "does this need a leading newline"
        // bookkeeping that produced a real, previously-undetected bug
        // (missing newline whenever $smtp was null and $oidc/$mas wasn't).
        $yaml .= view('k8s.chat.partials.email-block', ['smtp' => $smtp])->render();
        $yaml .= $mas !== null
            ? view('k8s.chat.partials.mas-auth-block', ['mas' => $mas])->render()
            : view('k8s.chat.partials.oidc-providers-block', ['oidc' => $oidc])->render();

        return $yaml;
    }

    /**
     * Whether MAS is deployed AND currently the active auth mode for
     * Synapse, read from the SAME `chat-mas-secrets` Secret `chat:init`'s
     * own deployMas() writes when it deploys the component — no separate
     * "cutover" marker Secret. `public_issuer` (MAS's own public subdomain,
     * needed for the org.matrix.msc2965.authentication well-known key) is
     * written there too, once, at deploy time — this method has no host
     * argument, so it never re-derives it.
     *
     * The precedence invariant that keeps an ALREADY-live install (with an
     * existing chat-oidc from classic Zitadel wiring) from silently
     * flipping to MAS out from under its real users lives in the CALLER
     * (ChatInitCommand::deployChat()), not here: it only ever passes this
     * method's result into rendering when readChatWiredOidc() is null. A
     * fresh install has no chat-oidc to begin with, so it activates MAS
     * immediately, in the same chat:init run that first deploys it — no
     * separate migration step exists or is needed for that case.
     *
     * @return array{endpoint: string, secret: string, public_issuer: string}|null
     */
    protected function readChatWiredMas(string $kubectl, string $ns): ?array
    {
        $read = function (string $key) use ($kubectl, $ns): ?string {
            $out = trim(Process::run(
                "{$kubectl} get secret chat-mas-secrets -n {$ns} -o jsonpath='{.data.{$key}}'",
            )->output());

            return $out !== '' ? (string) base64_decode($out) : null;
        };

        $secret = $read('trust-secret');
        if ($secret === null) {
            return null;
        }

        return [
            'endpoint' => 'http://chat-mas:8080/',
            'secret' => $secret,
            'public_issuer' => $read('public-issuer') ?? '',
        ];
    }

    /**
     * Patch MAS's own config.yaml with our real values, preserving
     * everything `mas-cli config generate` produced that we have no
     * business overwriting — most importantly `secrets:` (encryption +
     * signing keys): those are real cryptographic material this trait must
     * never fabricate or regenerate, since regenerating them on a re-run
     * would invalidate every existing session/cookie. Only `database:`,
     * `matrix:`, and `upstream_oauth2:` are ours to own — `http:`,
     * `secrets:`, `clients:`, `passwords:`, `account:` etc. stay whatever
     * the generated base file already has. Same strip-then-append idiom as
     * renderSynapseConfig(), applied to a generated base instead of a
     * hand-written skeleton.
     *
     * @param  array{host: string, user: string, password: string, database: string}  $database
     * @param  array{homeserver: string, secret: string}  $matrixTrust  homeserver = Synapse's server_name (chat host); secret = the Synapse↔MAS shared trust secret, NOT an OIDC value.
     * @param  array{id: string, issuer: string, client_id: string, client_secret: string}  $upstream  Zitadel as MAS's upstream IdP.
     */
    protected function renderMasConfig(string $baseYaml, array $database, array $matrixTrust, array $upstream): string
    {
        $yaml = $baseYaml;
        foreach (['database', 'matrix', 'upstream_oauth2'] as $key) {
            $yaml = (string) preg_replace('/^[ \t]*'.$key.':\n(?:[ \t]+[^\n]*\n)*/m', '', $yaml);
        }
        $yaml = rtrim($yaml)."\n";

        // Rendered via Blade — see renderSynapseCalling()'s comment for why.
        // `endpoint` in the `matrix` section below is cluster-internal only,
        // never exposed publicly: Synapse reaches MAS over it. The public
        // issuer Element X talks to is a separate concern (mas.{host}, see
        // readChatWiredMas()). `token_endpoint_auth_method: client_secret_basic`
        // matches what InteractsWithZitadelApi::zitadelCreateOidcApp() always
        // registers (OIDC_AUTH_METHOD_TYPE_BASIC) for confidential clients —
        // verify this is valid for the pinned MAS version before relying on
        // it; some MAS doc versions only show client_secret_post in examples.
        return $yaml.view('k8s.chat.partials.mas-config-sections', [
            'database' => $database,
            'matrixTrust' => $matrixTrust,
            'upstream' => $upstream,
        ])->render();
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
