<?php

namespace App\Traits;

use App\Enums\ClusterTool;
use App\Http\Integrations\Stalwart\Requests\JmapRequest;
use App\Http\Integrations\Stalwart\StalwartConnector;
use Illuminate\Support\Facades\Process;
use Saloon\Exceptions\Request\FatalRequestException;
use stdClass;

trait InteractsWithStalwartApi
{
    use PortForwardsToCluster;

    /**
     * Stalwart's "is this a domain we host" routing test. The directory
     * argument is REQUIRED — `is_local_domain('', rcpt_domain)` (empty string =
     * the default directory) is the two-arg form Stalwart ships as its own
     * next-hop default. The one-arg `is_local_domain(rcpt_domain)` never
     * evaluates true, which silently routes local mail to the outbound relay.
     *
     * @see https://stalw.art/docs/mta/outbound/strategy/
     */
    protected const LOCAL_DOMAIN_EXPR = 'is_local_domain(rcpt_domain)';

    /**
     * Every Ed25519 DKIM signature variant Stalwart can mint. BOTH generations
     * matter: the x:DkimSignature schema has four variants (Dkim1/Dkim2 ×
     * Ed25519/RSA), and an earlier version of the prune below matched only
     * Dkim1Ed25519Sha256 — so a Dkim2 Ed25519 key would survive and put the
     * second DKIM-Signature header straight back on outbound mail.
     *
     * @var list<string>
     */
    protected const DKIM_ED25519_TYPES = [
        'Dkim1Ed25519Sha256',
        'Dkim2Ed25519Sha256',
    ];

    /**
     * Name of the dedicated automation principal that owns the CLI's API key —
     * a service account kept separate from the human admin so the key's
     * lifecycle is independent of any person's mailbox.
     */
    protected const AUTOMATION_PRINCIPAL = 'larakube-automation';

    /** Re-entrancy guard: true while the key is being minted (its own JMAP calls must fall back to basic auth). */
    private bool $stalwartBootstrappingKey = false;

    /** Bootstrap is attempted at most once per process — a server with no domain yet must not retry on every call. */
    private bool $stalwartBootstrapAttempted = false;

    /** When true, JMAP auth ignores the API key and uses the recovery admin — the break-glass path for mail:recover. */
    private bool $stalwartForceRecoveryAuth = false;

    protected function stalwartPodName(string $kubectl, string $ns): string
    {
        // Stable, non-instance-suffixed label (mail-stalwart, not stalwart) —
        // matches isMailInstalled()'s fix, avoids needing to resolve the
        // instance just to find the pod.
        $pod = trim(Process::run("{$kubectl} get pod -l app=mail-stalwart -n {$ns} -o name --no-headers 2>/dev/null | head -1")->output());
        if ($pod !== '') {
            return $pod;
        }

        return 'deploy/'.ClusterTool::MAIL->deploymentName($this->resolveMailInstance($kubectl));
    }

    protected function stalwartBasicAuth(string $kubectl, string $ns): ?string
    {
        $password = $this->readMailSecret($kubectl, $ns, 'admin-password');
        if ($password === null) {
            return null;
        }

        return base64_encode('admin:'.$password);
    }

    /**
     * The Authorization header value for Stalwart's management API.
     *
     * Prefers the automation API KEY — a Stalwart-native Bearer credential
     * (mail-secrets/api-key). It's least-privilege (management API only, no mail
     * access) and, crucially, keeps working under full-OIDC because Stalwart
     * validates it against its own credential store, not the external directory.
     * Falls back to the recovery admin over Basic auth only when no key is stored
     * yet — i.e. first-run bootstrap (before the key is minted) or a rescue.
     * Returns null when neither credential is available.
     */
    protected function stalwartAuthHeader(string $kubectl, string $ns): ?string
    {
        // Break-glass: mail:recover forces the recovery admin, bypassing the API
        // key entirely (the key may be exactly what's broken).
        if ($this->stalwartForceRecoveryAuth) {
            $basic = $this->stalwartBasicAuth($kubectl, $ns);

            return $basic !== null ? 'Basic '.$basic : null;
        }

        $apiKey = $this->readMailSecret($kubectl, $ns, 'api-key');

        // First admin call of the process with no key yet: mint one, so we stop
        // authenticating as the recovery admin. Guarded so the mint's own JMAP
        // calls fall through to basic auth, and attempted at most once per run so
        // a not-yet-bootstrappable server (no domain) doesn't retry every call.
        if ($apiKey === null && ! $this->stalwartBootstrappingKey && ! $this->stalwartBootstrapAttempted) {
            $this->stalwartBootstrapAttempted = true;
            $this->stalwartBootstrappingKey = true;
            try {
                $apiKey = $this->stalwartEnsureApiKey($kubectl, $ns);
            } finally {
                $this->stalwartBootstrappingKey = false;
            }
        }

        if ($apiKey !== null && $apiKey !== '') {
            return 'Bearer '.$apiKey;
        }

        $basic = $this->stalwartBasicAuth($kubectl, $ns);

        return $basic !== null ? 'Basic '.$basic : null;
    }

    protected function stalwartJmap(string $kubectl, string $ns, array $methodCalls, array $using = ['urn:ietf:params:jmap:core', 'urn:stalwart:jmap']): ?array
    {
        $auth = $this->stalwartAuthHeader($kubectl, $ns);
        if ($auth === null) {
            return null;
        }

        $port = random_int(30100, 31100);
        $svc = ClusterTool::MAIL->deploymentName($this->resolveMailInstance($kubectl));
        $pf = Process::start("{$kubectl} port-forward -n {$ns} svc/{$svc} {$port}:8080");

        if (! $this->awaitLocalPort($port, $pf)) {
            if ($pf->running()) {
                $pf->stop(0, 2);
            }

            return null;
        }

        try {
            $response = (new StalwartConnector($port, $auth))->send(new JmapRequest($methodCalls, $using));

            if (! $response->successful()) {
                return null;
            }

            return $response->json('methodResponses');
        } catch (FatalRequestException) {
            return null;
        } finally {
            if ($pf->running()) {
                $pf->stop(0, 2);
            }
        }
    }

    /**
     * How many messages are sitting in Stalwart's outbound queue (undelivered
     * mail waiting on retries). A non-zero count is the fastest tell that
     * delivery is clogged — e.g. messages baked to a route that can't connect.
     * Returns null if the queue can't be read.
     */
    protected function stalwartQueueCount(string $kubectl, string $ns): ?int
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:QueuedMessage/query', ['filter' => new stdClass], 'c0'],
        ]);

        if ($responses === null) {
            return null;
        }

        $ids = $responses[0][1]['ids'] ?? null;

        return is_array($ids) ? count($ids) : null;
    }

    /**
     * Full outbound-queue listing — message objects with returnPath, createdAt,
     * and per-recipient status (incl. the last delivery error). Null on failure.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function stalwartQueuedMessages(string $kubectl, string $ns): ?array
    {
        $query = $this->stalwartJmap($kubectl, $ns, [
            ['x:QueuedMessage/query', ['filter' => new stdClass], 'c0'],
        ]);

        if ($query === null) {
            return null;
        }

        $ids = $query[0][1]['ids'] ?? [];
        if ($ids === []) {
            return [];
        }

        $get = $this->stalwartJmap($kubectl, $ns, [
            ['x:QueuedMessage/get', ['ids' => $ids], 'c1'],
        ]);

        return $get === null ? null : ($get[0][1]['list'] ?? []);
    }

    /**
     * Force an immediate delivery retry of the given queued messages (sets each
     * message's nextRetry to now). Returns how many were rescheduled.
     *
     * @param  array<int, string>  $ids
     */
    protected function stalwartRetryQueued(string $kubectl, string $ns, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $update = [];
        foreach ($ids as $id) {
            $update[$id] = ['nextRetry' => $now];
        }

        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:QueuedMessage/set', ['update' => $update], 'c1'],
        ]);

        return $responses === null ? 0 : count($responses[0][1]['updated'] ?? []);
    }

    /**
     * Drop the given messages from the outbound queue (they will not be
     * delivered). Returns how many were removed.
     *
     * @param  array<int, string>  $ids
     */
    protected function stalwartCancelQueued(string $kubectl, string $ns, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:QueuedMessage/set', ['destroy' => array_values($ids)], 'c1'],
        ]);

        return $responses === null ? 0 : count($responses[0][1]['destroyed'] ?? []);
    }

    protected function stalwartAccounts(string $kubectl, string $ns): ?array
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:Account/query', ['filter' => new stdClass], 'c0'],
            ['x:Account/get', ['ids' => []], 'c1'],
        ]);

        if ($responses === null || count($responses) < 2) {
            return null;
        }

        $ids = $responses[0][1]['ids'] ?? [];

        if ($ids === []) {
            return [];
        }

        $getResponses = $this->stalwartJmap($kubectl, $ns, [
            ['x:Account/get', ['ids' => $ids], 'c1'],
        ]);

        if ($getResponses === null) {
            return null;
        }

        return $getResponses[0][1]['list'] ?? [];
    }

    /**
     * The named MtaRoute (any @type) matching $name, or null if none exists.
     * Route names are immutable in Stalwart, so callers key off this to decide
     * create vs update.
     */
    protected function stalwartFindRoute(string $kubectl, string $ns, string $name): ?array
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:MtaRoute/query', ['filter' => new stdClass], 'c0'],
            ['x:MtaRoute/get', ['ids' => null], 'c1'],
        ]);

        if ($responses === null || count($responses) < 2) {
            return null;
        }

        foreach ($responses[1][1]['list'] ?? [] as $route) {
            if (($route['name'] ?? null) === $name) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Create or update a Relay-type MtaRoute (a smart-host outbound delivery
     * target). $name is immutable once created, so re-running with the same
     * name is idempotent — it patches the existing route in place.
     *
     * @return string|null the route's id, or null on failure
     */
    protected function stalwartUpsertRelayRoute(
        string $kubectl,
        string $ns,
        string $name,
        string $address,
        int $port,
        bool $implicitTls,
        string $username,
        string $password,
    ): ?string {
        $existing = $this->stalwartFindRoute($kubectl, $ns, $name);

        $props = [
            'address' => $address,
            'port' => $port,
            'protocol' => 'smtp',
            'implicitTls' => $implicitTls,
            'authUsername' => $username,
            'authSecret' => ['@type' => 'Value', 'secret' => $password],
        ];

        if ($existing !== null) {
            $id = $existing['id'];
            $responses = $this->stalwartJmap($kubectl, $ns, [
                ['x:MtaRoute/set', ['update' => [$id => $props]], 'c1'],
            ]);

            // A successful JMAP /set update returns `updated: {<id>: null}` — the
            // id maps to null, so isset() would misread success as failure.
            // array_key_exists() is the correct presence test here.
            $updated = $responses[0][1]['updated'] ?? [];

            return array_key_exists($id, $updated) ? $id : null;
        }

        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:MtaRoute/set', ['create' => ['r1' => ['@type' => 'Relay', 'name' => $name] + $props]], 'c1'],
        ]);

        return $responses[0][1]['created']['r1']['id'] ?? null;
    }

    /** Delete a route by id. */
    protected function stalwartDeleteRoute(string $kubectl, string $ns, string $id): bool
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:MtaRoute/set', ['destroy' => [$id]], 'c1'],
        ]);

        return in_array($id, $responses[0][1]['destroyed'] ?? [], true);
    }

    /**
     * The MtaOutboundStrategy singleton — its `route` field is an expression
     * ({else, match}) that resolves to the MtaRoute name used for each
     * outbound message. Stalwart's fixed id for this singleton is "singleton".
     */
    protected function stalwartOutboundStrategy(string $kubectl, string $ns): ?array
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:MtaOutboundStrategy/get', ['ids' => ['singleton']], 'c1'],
        ]);

        return $responses[0][1]['list'][0] ?? null;
    }

    /**
     * Point outbound delivery's `else` branch at $routeName (e.g. a relay),
     * preserving whatever `match` rules are already configured (default:
     * local-domain mail stays on the "local" route). Passing 'mx' reverts to
     * Stalwart's default direct-MX delivery.
     */
    protected function stalwartSetOutboundRoute(string $kubectl, string $ns, string $routeName): bool
    {
        $current = $this->stalwartOutboundStrategy($kubectl, $ns);
        $match = (array) ($current['route']['match'] ?? ['0' => ['if' => self::LOCAL_DOMAIN_EXPR, 'then' => "'local'"]]);

        // Repair a malformed local-domain rule before anything else. Stalwart's
        // expression function is is_local_domain(<directory>, <domain>) — TWO
        // arguments. An earlier version of this method defaulted to the one-arg
        // is_local_domain(rcpt_domain), which never evaluates true, and then
        // WROTE that rule back to the server whenever it patched `match`. On
        // such a server, local-domain mail stops matching rule 0 and falls all
        // the way through to the relay `else` branch, so inbound mail from
        // Gmail is handed to Brevo/SES (which reject it) instead of being
        // delivered to the mailbox — outbound keeps working, inbound dies.
        // Normalising here is what un-breaks an already-configured install.
        $matchChanged = false;
        foreach ($match as $key => $rule) {
            $if = (string) ($rule['if'] ?? '');
            if ($if !== '' && str_contains($if, 'is_local_domain(') && ! str_contains($if, ',')) {
                $match[$key]['if'] = self::LOCAL_DOMAIN_EXPR;
                $matchChanged = true;
            }
        }

        // Clean up any legacy authenticated_as guard rules written by older code,
        // as Stalwart rejects "authenticated_as" in MtaOutboundStrategy route context.
        $filtered = array_values(array_filter(
            $match,
            fn ($rule) => ! (isset($rule['if']) && str_contains((string) $rule['if'], 'authenticated_as')),
        ));
        $matchChanged = $matchChanged || count($filtered) !== count($match);
        $reIndexed = [];
        foreach ($filtered as $i => $rule) {
            $reIndexed[(string) $i] = $rule;
        }
        $match = $reIndexed;

        // Two separate JMAP calls: Stalwart v0.16 rejects full-route patches
        // that re-serialize the match expression alongside the else branch.
        // Sending else alone (proven via stalwart-cli) works reliably; match
        // updates only when the guard rule actually changed.
        $elseResponse = $this->stalwartJmap($kubectl, $ns, [
            ['x:MtaOutboundStrategy/set', ['update' => ['singleton' => [
                'route' => ['else' => "'{$routeName}'"],
            ]]], 'c1'],
        ]);

        $elseOk = array_key_exists('singleton', $elseResponse[0][1]['updated'] ?? []);

        if (! $matchChanged || ! $elseOk) {
            return $elseOk;
        }

        $matchResponse = $this->stalwartJmap($kubectl, $ns, [
            ['x:MtaOutboundStrategy/set', ['update' => ['singleton' => [
                'route' => ['match' => (object) $match],
            ]]], 'c2'],
        ]);

        return array_key_exists('singleton', $matchResponse[0][1]['updated'] ?? []);
    }

    /**
     * Allow all origins in Stalwart's CORS policy — the browser talks to JMAP
     * directly, cross-origin from the Bulwark webmail host.
     *
     * `x:Http` is a singleton config object, so this is a plain /set update
     * against the fixed id "singleton", exactly like MtaOutboundStrategy.
     * Supersedes a `POST /api/settings` write of `http.permissive-cors`: that
     * REST surface was removed in Stalwart 0.16 and 404s on every method, so
     * the old call silently did nothing (it was deliberately non-fatal, which
     * is why nothing ever surfaced). The manifest also sets
     * STALWART_HTTP_PERMISSIVE_CORS, so this is belt-and-braces for clusters
     * whose Deployment predates that env var.
     */
    protected function stalwartSetPermissiveCors(string $kubectl, string $ns): bool
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:Http/set', ['update' => ['singleton' => [
                'usePermissiveCors' => true,
            ]]], 'c1'],
        ]);

        return array_key_exists('singleton', $responses[0][1]['updated'] ?? []);
    }

    /**
     * Trust Traefik's X-Forwarded-For header when Stalwart decides which IP
     * to hold responsible for abuse/scan/auth-ban tracking (x:Security).
     * Without this every request Stalwart sees arrives from Traefik's own
     * pod IP — so one internet bot hitting a scan-bait path (e.g.
     * /wp-login.php) permanently bans Traefik itself and takes mail down for
     * every real user behind it. Confirmed live: this is exactly what was
     * banning send.luchtech.dev's Traefik pod on 2026-08-03.
     *
     * Safe to trust unconditionally here specifically because Stalwart's
     * HTTP listener has no hostPort and is unreachable from outside the
     * cluster — only Traefik can ever be the peer setting this header on a
     * request Stalwart receives, so nothing external can spoof it.
     *
     * `x:Http` is a singleton, same pattern as stalwartSetPermissiveCors().
     * Unlike that CORS write, this reloads via the ReloadSettings action
     * instead of requiring a Stalwart restart to take effect.
     */
    protected function stalwartTrustReverseProxy(string $kubectl, string $ns): bool
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:Http/set', ['update' => ['singleton' => [
                'useXForwarded' => true,
            ]]], 'c1'],
        ]);

        if (! array_key_exists('singleton', $responses[0][1]['updated'] ?? [])) {
            return false;
        }

        $this->stalwartJmap($kubectl, $ns, [
            ['x:Action/set', ['create' => ['r1' => ['@type' => 'ReloadSettings']]], 'c2'],
        ]);

        return true;
    }

    /**
     * Configured email domains (x:Domain — DNS/DKIM/TLS per domain). NOT
     * x:Tenant, which is Stalwart's unrelated multi-tenancy isolation concept;
     * querying that always came back empty even with real domains configured.
     */
    protected function stalwartDomains(string $kubectl, string $ns): ?array
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:Domain/query', ['filter' => new stdClass], 'c0'],
            ['x:Domain/get', ['ids' => []], 'c1'],
        ]);

        if ($responses === null || count($responses) < 2) {
            return null;
        }

        $ids = $responses[0][1]['ids'] ?? [];

        if ($ids === []) {
            return [];
        }

        $getResponses = $this->stalwartJmap($kubectl, $ns, [
            ['x:Domain/get', ['ids' => $ids], 'c1'],
        ]);

        if ($getResponses === null) {
            return null;
        }

        return $getResponses[0][1]['list'] ?? [];
    }

    /**
     * Every configured DKIM signature, with its domain name resolved.
     *
     * Each entry carries `stage` (see DkimRotationStage: active | pending |
     * retiring | retired). That distinction matters when judging duplicates:
     * Stalwart rotates keys on a schedule (`nextTransitionAt`), so a domain
     * legitimately holds more than one signature mid-rotation. Only *active*
     * keys are published and used for signing, so only two active signatures
     * of different algorithms produce the duplicate-header bounce.
     *
     * @return list<array{id: string, domain: string, selector: string, type: string, stage: string, isEd25519: bool}>|null
     */
    protected function stalwartDkimSignatures(string $kubectl, string $ns): ?array
    {
        $query = $this->stalwartJmap($kubectl, $ns, [
            ['x:DkimSignature/query', ['filter' => new stdClass], 'c0'],
        ]);

        if ($query === null) {
            return null;
        }

        $ids = $query[0][1]['ids'] ?? [];
        if ($ids === []) {
            return [];
        }

        $get = $this->stalwartJmap($kubectl, $ns, [
            ['x:DkimSignature/get', ['ids' => $ids], 'c1'],
        ]);

        if ($get === null) {
            return null;
        }

        // Signatures reference their domain by id only, so resolve names once.
        $domainNames = [];
        foreach ($this->stalwartDomains($kubectl, $ns) ?? [] as $domain) {
            $domainNames[$domain['id'] ?? ''] = $domain['name'] ?? '-';
        }

        $signatures = [];
        foreach ($get[0][1]['list'] ?? [] as $sig) {
            $type = (string) ($sig['@type'] ?? '-');

            $signatures[] = [
                'id' => (string) ($sig['id'] ?? ''),
                'domain' => $domainNames[$sig['domainId'] ?? ''] ?? ($sig['domainId'] ?? '-'),
                'selector' => (string) ($sig['selector'] ?? '-'),
                'type' => $type,
                'stage' => (string) ($sig['stage'] ?? '-'),
                'isEd25519' => in_array($type, self::DKIM_ED25519_TYPES, true),
            ];
        }

        return $signatures;
    }

    /**
     * Domains carrying more than one ACTIVE signature — the exact state that
     * stamps two DKIM-Signature headers on a message and trips SES's 554
     * duplicate-header rejection. Keyed by domain name.
     *
     * @param  list<array{domain: string, stage: string, ...}>  $signatures
     * @return array<string, int>
     */
    protected function stalwartDuplicateActiveDkim(array $signatures): array
    {
        $activePerDomain = [];

        foreach ($signatures as $sig) {
            if (($sig['stage'] ?? '') !== 'active') {
                continue;
            }

            $domain = $sig['domain'];
            $activePerDomain[$domain] = ($activePerDomain[$domain] ?? 0) + 1;
        }

        return array_filter($activePerDomain, fn (int $count) => $count > 1);
    }

    /**
     * Enforce RSA-only DKIM by destroying every Ed25519 signature, preventing
     * the duplicate DKIM-Signature headers that make SES reject with 554.
     *
     * RSA is the side that survives, deliberately: it's what the live servers
     * already publish, and it's the broadly-compatible algorithm. Pending and
     * retiring Ed25519 keys are pruned too — leaving one would just re-create
     * the duplicate when rotation promotes it to active.
     *
     * Returns the number destroyed, or null if Stalwart couldn't be reached —
     * callers need to tell "nothing to do" (0) apart from "never ran" (null).
     */
    protected function stalwartEnforceSingleRsaDkimSignature(string $kubectl, string $ns): ?int
    {
        $signatures = $this->stalwartDkimSignatures($kubectl, $ns);

        if ($signatures === null) {
            return null;
        }

        $toDestroy = array_values(array_map(
            fn (array $sig) => $sig['id'],
            array_filter($signatures, fn (array $sig) => $sig['isEd25519']),
        ));

        if ($toDestroy === []) {
            return 0;
        }

        $destroyResponse = $this->stalwartJmap($kubectl, $ns, [
            ['x:DkimSignature/set', ['destroy' => $toDestroy], 'c2'],
        ]);

        if ($destroyResponse === null) {
            return null;
        }

        return count($destroyResponse[0][1]['destroyed'] ?? []);
    }

    /**
     * The account id of the dedicated automation principal, creating it if
     * absent. A service account (Admin role, zero mailbox quota) that owns the
     * CLI's API key. Needs a configured domain to attach to — returns null when
     * none exists yet (the caller then stays on recovery-admin basic auth until
     * a domain is set up, e.g. before the first-run wizard completes).
     */
    protected function stalwartAutomationPrincipalId(string $kubectl, string $ns): ?string
    {
        foreach ($this->stalwartAccounts($kubectl, $ns) ?? [] as $account) {
            if (($account['name'] ?? '') === self::AUTOMATION_PRINCIPAL) {
                return $account['id'] ?? null;
            }
        }

        $domainId = ($this->stalwartDomains($kubectl, $ns)[0]['id'] ?? null);
        if ($domainId === null) {
            return null;
        }

        $created = $this->stalwartJmap($kubectl, $ns, [
            ['x:Account/set', ['create' => ['bot' => [
                '@type' => 'User',
                'name' => self::AUTOMATION_PRINCIPAL,
                'domainId' => $domainId,
                'description' => 'LaraKube CLI automation (API key owner)',
                'roles' => ['@type' => 'Admin'],
                'permissions' => ['@type' => 'Inherit'],
                'quotas' => ['maxDiskQuota' => 0],
                'encryptionAtRest' => ['@type' => 'Disabled'],
            ]]], 'c1'],
        ]);

        return $created[0][1]['created']['bot']['id'] ?? null;
    }

    /**
     * Mint an API key owned by $principalId and return its secret. Stalwart
     * generates the secret server-side and returns it exactly ONCE (it's stored
     * hashed), so the caller must persist it immediately. The key authenticates
     * as `Authorization: Bearer <secret>` and is scoped to the management API —
     * it cannot read mail. Returns null on failure.
     */
    protected function stalwartMintApiKey(string $kubectl, string $ns, string $principalId): ?string
    {
        $response = $this->stalwartJmap($kubectl, $ns, [
            ['x:ApiKey/set', [
                'accountId' => $principalId,
                'create' => ['k1' => ['description' => 'larakube-cli']],
            ], 'c1'],
        ]);

        return $response[0][1]['created']['k1']['secret'] ?? null;
    }

    /**
     * The automation API key, minting + persisting it to mail-secrets on first
     * use. Idempotent: returns the stored key when present. Returns null when it
     * can't bootstrap yet (no domain) — the caller keeps working via the
     * recovery-admin fallback in stalwartAuthHeader().
     *
     * Bootstrap runs over the recovery-admin basic auth (there is no key yet, so
     * stalwartAuthHeader falls back), then every subsequent call uses the key.
     * mail-secrets is the SINGLE source of truth — the key is deliberately not
     * mirrored anywhere (it's CLI-internal and regenerable via mail:recover, so
     * a copy would only drift with nothing to read it).
     */
    protected function stalwartEnsureApiKey(string $kubectl, string $ns): ?string
    {
        $existing = $this->readMailSecret($kubectl, $ns, 'api-key');
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        // Self-guard so the mint's own JMAP calls fall back to recovery-admin
        // basic auth instead of recursively trying to bootstrap. Holds whether
        // this is reached via stalwartAuthHeader's lazy path (guard already set)
        // or called directly.
        $previous = $this->stalwartBootstrappingKey;
        $this->stalwartBootstrappingKey = true;

        try {
            $principalId = $this->stalwartAutomationPrincipalId($kubectl, $ns);
            if ($principalId === null) {
                return null;
            }

            $key = $this->stalwartMintApiKey($kubectl, $ns, $principalId);
            if ($key === null) {
                return null;
            }

            $this->storeMailSecret($kubectl, $ns, 'api-key', $key);

            return $key;
        } finally {
            $this->stalwartBootstrappingKey = $previous;
        }
    }

    /**
     * Rotate the automation API key: destroy every key on the automation
     * principal and mint a fresh one, persisting it to mail-secrets. This is the
     * rescue path (mail:recover) for a lost or broken key — callers set
     * $stalwartForceRecoveryAuth first so the JMAP calls authenticate as the
     * recovery admin rather than the (possibly dead) key being replaced.
     * Returns the new secret, or null on failure.
     */
    protected function stalwartResetApiKey(string $kubectl, string $ns): ?string
    {
        $principalId = $this->stalwartAutomationPrincipalId($kubectl, $ns);
        if ($principalId === null) {
            return null;
        }

        $existing = $this->stalwartJmap($kubectl, $ns, [
            ['x:ApiKey/query', ['accountId' => $principalId, 'filter' => new stdClass], 'c0'],
        ]);
        $keyIds = $existing[0][1]['ids'] ?? [];
        if ($keyIds !== []) {
            $this->stalwartJmap($kubectl, $ns, [
                ['x:ApiKey/set', ['accountId' => $principalId, 'destroy' => array_values($keyIds)], 'c1'],
            ]);
        }

        $key = $this->stalwartMintApiKey($kubectl, $ns, $principalId);
        if ($key === null) {
            return null;
        }

        $this->storeMailSecret($kubectl, $ns, 'api-key', $key);

        return $key;
    }

    /** Run $callback with JMAP authenticating as the recovery admin (never the API key). */
    protected function withStalwartRecoveryAuth(callable $callback): mixed
    {
        $previous = $this->stalwartForceRecoveryAuth;
        $this->stalwartForceRecoveryAuth = true;
        try {
            return $callback();
        } finally {
            $this->stalwartForceRecoveryAuth = $previous;
        }
    }

    /**
     * Every configured DNS server (x:DnsServer — automatic-DNS-management
     * credentials, one per zone/provider account). Not domain-scoped itself;
     * an x:Domain references one of these by id.
     */
    protected function stalwartDnsServers(string $kubectl, string $ns): ?array
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:DnsServer/query', ['filter' => new stdClass], 'c0'],
            ['x:DnsServer/get', ['ids' => []], 'c1'],
        ]);

        if ($responses === null || count($responses) < 2) {
            return null;
        }

        $ids = $responses[0][1]['ids'] ?? [];
        if ($ids === []) {
            return [];
        }

        $getResponses = $this->stalwartJmap($kubectl, $ns, [
            ['x:DnsServer/get', ['ids' => $ids], 'c1'],
        ]);

        return $getResponses === null ? null : ($getResponses[0][1]['list'] ?? []);
    }

    /**
     * The DnsServer whose `description` equals $description, or null.
     * x:DnsServer has no `name` field — description is the only stable,
     * human-chosen string on the object, so it doubles as the idempotency key
     * (callers set it to the exact zone, e.g. "partner.example").
     */
    protected function stalwartFindDnsServerByDescription(string $kubectl, string $ns, string $description): ?array
    {
        foreach ($this->stalwartDnsServers($kubectl, $ns) ?? [] as $server) {
            if (($server['description'] ?? null) === $description) {
                return $server;
            }
        }

        return null;
    }

    /**
     * Retrieve the stored Cloudflare API token for $zone from Stalwart.
     * Searches by matching x:DnsServer description first, then checks configured domains.
     */
    protected function stalwartGetZoneCloudflareToken(string $kubectl, string $ns, string $zone): ?string
    {
        // 1. Try finding DnsServer directly by description
        $server = $this->stalwartFindDnsServerByDescription($kubectl, $ns, $zone);
        if ($server !== null && ! empty($server['secret']['secret'])) {
            return $server['secret']['secret'];
        }

        // 2. Try looking up through domain's dnsManagement.dnsServerId
        $dnsServers = $this->stalwartDnsServers($kubectl, $ns) ?? [];
        $dnsServersById = [];
        foreach ($dnsServers as $s) {
            if (isset($s['id'])) {
                $dnsServersById[$s['id']] = $s;
            }
        }

        foreach ($this->stalwartDomains($kubectl, $ns) ?? [] as $domain) {
            if (($domain['name'] ?? null) === $zone) {
                $dnsServerId = $domain['dnsManagement']['dnsServerId'] ?? null;
                if ($dnsServerId && isset($dnsServersById[$dnsServerId])) {
                    $token = $dnsServersById[$dnsServerId]['secret']['secret'] ?? null;
                    if ($token !== null && $token !== '') {
                        return $token;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Create or update a Cloudflare-backed x:DnsServer for $zone, holding the
     * zone-scoped API token as its `secret`. Idempotent by `description` (see
     * stalwartFindDnsServerByDescription()) — re-running with the same zone
     * patches the existing server's token/email in place rather than creating
     * a duplicate. Returns the server's id, or null on failure.
     */
    protected function stalwartUpsertCloudflareDnsServer(string $kubectl, string $ns, string $zone, string $token, ?string $email = null): ?string
    {
        $existing = $this->stalwartFindDnsServerByDescription($kubectl, $ns, $zone);

        $props = [
            'description' => $zone,
            'email' => $email,
            'secret' => ['@type' => 'Value', 'secret' => $token],
        ];

        if ($existing !== null) {
            $id = $existing['id'];
            $responses = $this->stalwartJmap($kubectl, $ns, [
                ['x:DnsServer/set', ['update' => [$id => $props]], 'c1'],
            ]);

            $updated = $responses[0][1]['updated'] ?? [];

            return array_key_exists($id, $updated) ? $id : null;
        }

        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:DnsServer/set', ['create' => ['d1' => ['@type' => 'Cloudflare'] + $props]], 'c1'],
        ]);

        return $responses[0][1]['created']['d1']['id'] ?? null;
    }

    /**
     * Every configured ACME provider (x:AcmeProvider — a Let's Encrypt-style
     * account issuing certs, potentially for many domains at once).
     */
    protected function stalwartAcmeProviders(string $kubectl, string $ns): ?array
    {
        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:AcmeProvider/query', ['filter' => new stdClass], 'c0'],
            ['x:AcmeProvider/get', ['ids' => []], 'c1'],
        ]);

        if ($responses === null || count($responses) < 2) {
            return null;
        }

        $ids = $responses[0][1]['ids'] ?? [];
        if ($ids === []) {
            return [];
        }

        $getResponses = $this->stalwartJmap($kubectl, $ns, [
            ['x:AcmeProvider/get', ['ids' => $ids], 'c1'],
        ]);

        return $getResponses === null ? null : ($getResponses[0][1]['list'] ?? []);
    }

    /**
     * Find-or-create an ACME provider for $directory (default: Let's Encrypt
     * production). One ACME account legitimately issues certs for many
     * unrelated domains — `contact`/`directory` are account-level, not
     * domain-scoped — so a second onboarded domain REUSES the existing
     * provider instead of registering a new account. Only creates one when no
     * provider for that directory exists yet.
     *
     * Deliberately overrides Stalwart's own default challengeType
     * (TlsAlpn01, which needs Stalwart itself to answer the raw TLS
     * handshake on the domain's port 443 — already owned by Traefik on this
     * cluster) to Dns01, which validates by writing an
     * `_acme-challenge.<domain>` TXT record through the domain's own
     * x:DnsServer — fully programmatic, no port contention.
     *
     * Returns the provider's id, or null on failure.
     */
    protected function stalwartEnsureAcmeProvider(string $kubectl, string $ns, ?string $contactEmail = null, ?string $directory = null): ?string
    {
        $directory ??= 'https://acme-v02.api.letsencrypt.org/directory';

        foreach ($this->stalwartAcmeProviders($kubectl, $ns) ?? [] as $provider) {
            if (($provider['directory'] ?? null) === $directory) {
                return $provider['id'] ?? null;
            }
        }

        $props = [
            'directory' => $directory,
            'challengeType' => 'Dns01',
        ];
        if ($contactEmail !== null && $contactEmail !== '') {
            // JMAP Set<String> serializes as an object map (value => true), not
            // a JSON array — confirmed against the live luchtech.dev provider,
            // whose own `contact` is `{"mailto:postmaster@luchtech.dev": true}`.
            // The `mailto:` scheme prefix is required, matching that shape.
            $props['contact'] = (object) ["mailto:{$contactEmail}" => true];
        }

        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:AcmeProvider/set', ['create' => ['a1' => $props]], 'c1'],
        ]);

        return $responses[0][1]['created']['a1']['id'] ?? null;
    }

    /**
     * Create or update the x:Domain for $zone with fully automatic DNS,
     * certificate, and DKIM management. Idempotent by `name` (via the
     * existing stalwartDomains()) — re-running patches the existing domain's
     * dnsServerId/acmeProviderId in place rather than creating a duplicate.
     *
     * dkimManagement is explicitly restricted to RSA-only
     * (Dkim1RsaSha256): Stalwart's own default enables RSA + Ed25519
     * simultaneously, which is exactly the dual-active-signature condition
     * that trips SES's 554 duplicate-header rejection (see
     * stalwartEnforceSingleRsaDkimSignature(), written as a CLEANUP for that
     * on luchtech.dev). Setting it explicitly at creation avoids ever needing
     * that cleanup here.
     *
     * Returns the domain's id, or null on failure.
     */
    protected function stalwartUpsertDomain(string $kubectl, string $ns, string $zone, string $dnsServerId, string $acmeProviderId): ?string
    {
        $existing = null;
        foreach ($this->stalwartDomains($kubectl, $ns) ?? [] as $domain) {
            if (($domain['name'] ?? null) === $zone) {
                $existing = $domain;
                break;
            }
        }

        $props = [
            'isEnabled' => true,
            'dnsManagement' => ['@type' => 'Automatic', 'dnsServerId' => $dnsServerId],
            'certificateManagement' => ['@type' => 'Automatic', 'acmeProviderId' => $acmeProviderId],
            // 'algorithms' is a JMAP Set<enum>, which serializes as an object
            // map (value => true), not a JSON array — confirmed against the
            // live luchtech.dev domain's own dkimManagement.algorithms shape
            // ({"Dkim1Ed25519Sha256": true, "Dkim1RsaSha256": true}).
            'dkimManagement' => ['@type' => 'Automatic', 'algorithms' => (object) ['Dkim1RsaSha256' => true]],
        ];

        if ($existing !== null) {
            $id = $existing['id'];
            $responses = $this->stalwartJmap($kubectl, $ns, [
                ['x:Domain/set', ['update' => [$id => $props]], 'c1'],
            ]);

            $updated = $responses[0][1]['updated'] ?? [];

            return array_key_exists($id, $updated) ? $id : null;
        }

        $responses = $this->stalwartJmap($kubectl, $ns, [
            ['x:Domain/set', ['create' => ['dm1' => ['name' => $zone] + $props]], 'c1'],
        ]);

        return $responses[0][1]['created']['dm1']['id'] ?? null;
    }
}
