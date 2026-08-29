<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Http\Integrations\Netbird\NetbirdConnector;
use App\Http\Integrations\Netbird\Requests\CreateGroupRequest;
use App\Http\Integrations\Netbird\Requests\CreateIdentityProviderRequest;
use App\Http\Integrations\Netbird\Requests\CreateSetupKeyRequest;
use App\Http\Integrations\Netbird\Requests\DeleteAccountRequest;
use App\Http\Integrations\Netbird\Requests\DeleteIdentityProviderRequest;
use App\Http\Integrations\Netbird\Requests\DeleteNameserverGroupRequest;
use App\Http\Integrations\Netbird\Requests\DeletePeerRequest;
use App\Http\Integrations\Netbird\Requests\ListAccountsRequest;
use App\Http\Integrations\Netbird\Requests\ListGroupsRequest;
use App\Http\Integrations\Netbird\Requests\ListIdentityProvidersRequest;
use App\Http\Integrations\Netbird\Requests\ListNameserverGroupsRequest;
use App\Http\Integrations\Netbird\Requests\ListPeersRequest;
use App\Http\Integrations\Netbird\Requests\ListPersonalAccessTokensRequest;
use App\Http\Integrations\Netbird\Requests\ListSetupKeysRequest;
use App\Http\Integrations\Netbird\Requests\ListUsersRequest;
use App\Http\Integrations\Netbird\Requests\SaveNameserverGroupRequest;
use App\Http\Integrations\Netbird\Requests\UpdateIdentityProviderRequest;
use App\Http\Integrations\Netbird\Requests\UpdateSetupKeyRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Throwable;

trait InteractsWithVpn
{
    use InteractsWithToolRegistry, ResolvesEnvironmentContext, SyncsClusterSecrets;

    /** Cluster-level group for the in-cluster gateway peer(s). */
    public const VPN_GROUP_ROUTERS = 'larakube-routers';

    /** Cluster-level group every human device lands in unless told otherwise. */
    public const VPN_GROUP_PEOPLE = 'larakube-people';

    /** Port the split-DNS resolver listens on. NOT 53: the NetBird client binds its own DNS to <overlay-ip>:53 inside the very same pod. */
    public const VPN_RESOLVER_PORT = 5353;

    public const VPN_NAMESERVER_GROUP = 'LaraKube Cluster Internal';

    /** The dedicated namespace the NetBird VPN lives in. */
    /** Memoised per command run — the registry lookup is a kubectl call. */
    private ?string $vpnInstanceCache = null;

    /**
     * The instance slug every VPN resource is suffixed with, resolved from the
     * tool registry rather than threaded through a dozen signatures.
     *
     * Empty string when VPN is not registered yet — which is exactly right for
     * a first `vpn:init`, whose resources are rendered from the host it already
     * has and which registers itself only after deploying.
     */
    protected function vpnInstance(string $kubectl): string
    {
        if ($this->vpnInstanceCache !== null) {
            return $this->vpnInstanceCache;
        }

        $host = $this->getToolHost($kubectl, ClusterTool::VPN);

        return $this->vpnInstanceCache = $host !== null ? ClusterTool::VPN->instanceSlugFromHost($host) : '';
    }

    /**
     * Host-based sibling of vpnName(), for vpn:init — which renders and waits on
     * these resources BEFORE it registers the tool, so the registry lookup
     * vpnName() uses would still be empty.
     */
    protected function vpnNameForHost(string $base, string $host): string
    {
        $instance = ClusterTool::VPN->instanceSlugFromHost($host);

        return $instance === '' ? $base : "{$base}-{$instance}";
    }

    /**
     * Store a new PAT so it actually survives.
     *
     * Patching the Kubernetes Secret alone is not enough once VpnTool declares a
     * KV sync for `pat`: that ExternalSecret refreshes every 60s with
     * `creationPolicy: Merge`, so it would quietly put the OLD value back and the
     * command would look like it had worked. OpenBao is the source of truth for
     * this key when it is present, so write there first and let ESO propagate —
     * the Secret patch stays for immediate effect and for clusters with no
     * OpenBao at all.
     *
     * @return bool whether the Kubernetes Secret was patched
     */
    protected function persistVpnPat(string $kubectl, string $pat, string $env): bool
    {
        $keyMap = ClusterTool::VPN->openbaoSyncConfig($this->vpnInstance($kubectl))['keyMap'] ?? [];
        $kvKey = array_key_first($keyMap);

        if ($kvKey !== null && $this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
            $this->pushClusterSecret($kubectl, $kvKey, $pat, $env === 'local' ? 'local' : 'production');
        }

        return Process::run(
            "{$kubectl} patch secret ".$this->vpnName('vpn-management-secrets', $kubectl)
            ." -n {$this->vpnNamespace()} --type=merge -p "
            .escapeshellarg((string) json_encode(['data' => ['pat' => base64_encode($pat)]], JSON_THROW_ON_ERROR)),
        )->successful();
    }

    /** `vpn-management` → `vpn-management-vpn-luchtech-dev`, per the naming convention. */
    protected function vpnName(string $base, string $kubectl): string
    {
        $instance = $this->vpnInstance($kubectl);

        return $instance === '' ? $base : "{$base}-{$instance}";
    }

    protected function vpnNamespace(): string
    {
        return ClusterTool::VPN->namespace();
    }

    /** Build the kubectl command, optionally scoped to a specific context, pinned to ~/.kube/config. */
    protected function vpnKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /**
     * The env's own kube-context — an explicit --context always wins, otherwise
     * the CHOSEN environment's saved cloud target (never the ambient
     * current-context, which would otherwise silently target whatever cluster
     * happened to be active regardless of the environment argument/prompt).
     */
    protected function resolveVpnContext(string $env, ?ConfigData $config): ?string
    {
        $contextOption = (string) ($this->option('context') ?? '');
        if ($contextOption !== '') {
            return $contextOption;
        }

        return $config ? $this->environmentContextOrCurrent($config, $env) : null;
    }

    /** NetBird management Deployment present? A cheap "is NetBird installed" probe. */
    protected function isVpnInstalled(string $kubectl, string $ns): bool
    {
        $deployment = $this->vpnName('vpn-management', $kubectl);
        $out = Process::run("{$kubectl} get deployment {$deployment} -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /**
     * Read the reusable setup key `vpn:init` bootstrapped, from the k8s Secret
     * it wrote (`kubectl create secret ... vpn-secrets`). One bootstrap,
     * shared by every teammate with kubectl access — used by both `vpn:join`
     * (this developer's own machine) and `cloud:harden` (the VPS host itself).
     */
    protected function fetchVpnSetupKey(string $kubectl, string $ns): ?string
    {
        $encoded = trim(Process::run(
            "{$kubectl} get secret ".$this->vpnName('vpn-management-secrets', $kubectl)." -n {$ns} -o jsonpath='{.data.setup-key}'",
        )->output());

        if ($encoded === '') {
            return null;
        }

        $key = base64_decode($encoded, true);

        return $key !== false && $key !== '' ? $key : null;
    }

    /**
     * Read the NetBird owner's Personal Access Token from the same k8s Secret
     * `vpn:init` bootstrapped (`vpn-secrets`), same shape as
     * fetchVpnSetupKey() but the `pat` field instead of `setup-key`. Used to
     * call NetBird's REST API (minting/listing/revoking setup keys) on the
     * operator's behalf — vpn:grant/vpn:revoke/vpn:users.
     */
    protected function fetchVpnPat(string $kubectl, string $ns): ?string
    {
        $encoded = trim(Process::run(
            "{$kubectl} get secret ".$this->vpnName('vpn-management-secrets', $kubectl)." -n {$ns} -o jsonpath='{.data.pat}'",
        )->output());

        if ($encoded === '') {
            return null;
        }

        $pat = base64_decode($encoded, true);

        return $pat !== false && $pat !== '' ? $pat : null;
    }

    /**
     * Read-only NetBird host for an env: local → vpn.{dev tld}; a cloud env →
     * the host persisted in .larakube.json (null when not configured yet). Never
     * prompts or persists.
     */
    protected function resolveVpnHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::VPN;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /**
     * Whether SSO has been wired for VPN yet — the precondition for deploying
     * the NetBird dashboard, which runs its own OIDC flow and is useless
     * without a client to run it against.
     *
     * `sso:wire vpn` writes netbird-oidc only after the identity-provider call
     * to NetBird actually succeeded, so the secret's presence is a real signal
     * rather than a marker someone might have left behind.
     */
    protected function vpnSsoWired(string $kubectl, string $ns): bool
    {
        return $this->readClusterSecretKey($kubectl, $ns, $this->vpnName('vpn-management-oidc', $kubectl), 'client-id') !== null;
    }

    /**
     * The domain NetBird groups every SSO login under (single-account mode).
     *
     * This is the users' EMAIL domain, which is not necessarily the cluster's
     * base domain — the two are free to diverge, so the derived value is only a
     * default and --sso-domain overrides it. Derivation is the inverse of
     * SharedClusterService::hostFor(): strip the service prefix off the host.
     */
    protected function vpnSsoDomain(string $host, ?string $override = null): string
    {
        $override = trim((string) $override);

        if ($override !== '') {
            return ltrim(strtolower($override), '@');
        }

        $prefix = SharedClusterService::VPN->hostPrefix();

        return str_starts_with($host, "{$prefix}.")
            ? substr($host, strlen($prefix) + 1)
            : $host;
    }

    /**
     * Read whether netbird-management actually enabled single-account mode, and
     * how many accounts it counted, from its startup log.
     *
     * The mode is what makes "one company" mean "one network": with it on,
     * NetBird overwrites every login's domain claim with the configured domain,
     * so every SSO user lands in the same account as the in-cluster gateway.
     * With it off, each login mints its own account with its own /16 and no
     * route to anything we deploy.
     *
     * It is decided once per process start, as
     * `singleAccountModeDomain != "" && accountsCounter <= 1`, so it re-evaluates
     * on every restart and cannot decay on its own — a database outage or a
     * rollout does not put it at risk. The only way to lose it is a login during
     * a window where it was already off, which pushes the count past 1 for good:
     * nothing lowers that count again short of deleting accounts, and NetBird
     * exposes neither an API nor an admin-CLI path for that (`GET /api/accounts`
     * returns only the caller's own account).
     *
     * Returns null when the line has aged out of the retained log window, which
     * is normal for a long-running pod and is not itself a problem.
     *
     * @return array{enabled: bool, accounts: int}|null
     */
    protected function vpnSingleAccountState(string $kubectl, string $ns): ?array
    {
        $logs = Process::timeout(30)->run(
            "{$kubectl} logs deploy/".$this->vpnName('vpn-management', $kubectl)." -n {$ns} --tail=2000",
        )->output();

        if (preg_match_all('/single account mode (enabled|disabled), accounts number (\d+)/i', $logs, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        // Last wins: a pod that logged more than one line has re-built its
        // manager, and only the most recent decision is in force.
        $last = end($matches);

        return [
            'enabled' => strtolower($last[1]) === 'enabled',
            'accounts' => (int) $last[2],
        ];
    }

    /**
     * Group id for $name, creating the group if it does not exist yet.
     *
     * Look-then-create rather than create-and-ignore-conflict: the API does not
     * enforce unique group names, so a blind create leaves duplicates that are
     * indistinguishable in the dashboard and silently split a policy's scope.
     *
     * Returns null if the group could neither be found nor created — callers
     * treat that as "no auto_groups", never as a failure worth aborting for.
     */
    protected function ensureVpnGroup(string $host, string $pat, string $name): ?string
    {
        try {
            $groups = NetbirdConnector::make($host, $pat)->send(ListGroupsRequest::make());

            if (! $groups->failed()) {
                foreach ($groups->json() ?? [] as $group) {
                    if (($group['name'] ?? null) === $name) {
                        return $group['id'] ?? null;
                    }
                }
            }

            $created = NetbirdConnector::make($host, $pat)->send(CreateGroupRequest::make($name));

            return $created->failed() ? null : $created->json('id');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ids for the cluster-level groups, creating whichever are missing.
     *
     * vpn:init is cluster-scoped while a blueprint is per-project, so it cannot
     * enumerate the apps or environments sharing this cluster — only these two
     * groups are knowable at install time. Per-app-environment groups
     * (`{project}-{env}`, mirroring the namespace convention) are created lazily
     * by commands that do run in a project context.
     *
     * @return array{routers: ?string, people: ?string}
     */
    protected function ensureVpnBaseGroups(string $host, string $pat): array
    {
        return [
            'routers' => $this->ensureVpnGroup($host, $pat, self::VPN_GROUP_ROUTERS),
            'people' => $this->ensureVpnGroup($host, $pat, self::VPN_GROUP_PEOPLE),
        ];
    }

    /**
     * Mint a NetBird setup key via the REST API — vpn:grant. $ephemeral marks
     * any peer that joins through this key for auto-removal once it goes
     * stale/disconnects — for a CI runner's throwaway peer identity, not a
     * person's device. Returns the decoded response (its `key` field is
     * plaintext, only ever returned on create — every later GET redacts it),
     * or null on any HTTP failure.
     *
     * @return array<string, mixed>|null
     */
    protected function mintVpnSetupKey(string $host, string $pat, string $name, bool $reusable, int $days, bool $ephemeral = false, array $autoGroups = []): ?array
    {
        $response = NetbirdConnector::make($host, $pat)->send(CreateSetupKeyRequest::make(
            $name,
            $days * 86400,
            $reusable ? 0 : 1,
            $ephemeral,
            $autoGroups,
        ));

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) && ! empty($data['key']) ? $data : null;
    }

    /**
     * List setup keys via the REST API — vpn:users/vpn:revoke. The `key`
     * field is redacted server-side on every entry (e.g. "2A7A9****") — only
     * mintVpnSetupKey()'s create response ever holds the plaintext value.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function listVpnSetupKeys(string $host, string $pat): ?array
    {
        $response = NetbirdConnector::make($host, $pat)->send(ListSetupKeysRequest::make());

        if ($response->failed()) {
            return null;
        }

        $keys = $response->json();

        return is_array($keys) ? $keys : null;
    }

    /**
     * Soonest expiry among the credentials LaraKube stores, as whole days from
     * now, keyed by label. Empty when nothing could be read.
     *
     * vpn:init mints the PAT and the setup key in one call, so they expire
     * within milliseconds of each other — and once the PAT is gone it cannot
     * mint its own replacement, leaving no API path back in. Surfacing the
     * countdown is what makes `vpn:rotate` a safety net rather than something
     * you have to remember unprompted.
     *
     * @return array<string, int>
     */
    /**
     * The id of the user the stored PAT belongs to.
     *
     * Prefers NetBird's own `is_current` (computed server-side as
     * `user.ID == currentUserID` from the auth claims, and service users ARE
     * included in the default listing). Falls back to matching the
     * `larakube-cli` service user by name, so this keeps working even if a
     * future release stops flagging service-user tokens as current — the
     * difference between vpn:rotate renewing the PAT and a hard lockout at
     * day 365 is not worth resting on one upstream field.
     */
    protected function vpnCurrentUserId(string $host, string $pat): ?string
    {
        $users = NetbirdConnector::make($host, $pat)->send(ListUsersRequest::make());

        if ($users->failed()) {
            return null;
        }

        $rows = (array) $users->json();

        foreach ($rows as $user) {
            if (($user['is_current'] ?? false) === true) {
                return ((string) ($user['id'] ?? '')) ?: null;
            }
        }

        foreach ($rows as $user) {
            if (($user['is_service_user'] ?? false) && ($user['name'] ?? null) === 'larakube-cli') {
                return ((string) ($user['id'] ?? '')) ?: null;
            }
        }

        return null;
    }

    protected function vpnCredentialExpiryDays(string $host, string $pat): array
    {
        $out = [];

        $userId = $this->vpnCurrentUserId($host, $pat);

        if ($userId !== null) {
            $tokens = NetbirdConnector::make($host, $pat)
                ->send(ListPersonalAccessTokensRequest::make($userId));

            if (! $tokens->failed()) {
                foreach ((array) $tokens->json() as $token) {
                    $days = $this->vpnDaysUntil($token['expiration_date'] ?? null);
                    if ($days !== null && (! isset($out['PAT']) || $days < $out['PAT'])) {
                        $out['PAT'] = $days;
                    }
                }
            }
        }

        foreach ($this->listVpnSetupKeys($host, $pat) ?? [] as $key) {
            // A revoked or already-invalid key is not a countdown worth showing.
            if (($key['valid'] ?? true) === false || ($key['revoked'] ?? false) === true) {
                continue;
            }
            $days = $this->vpnDaysUntil($key['expires'] ?? null);
            if ($days !== null && (! isset($out['Setup key']) || $days < $out['Setup key'])) {
                $out['Setup key'] = $days;
            }
        }

        return $out;
    }

    /** Whole days from now until an RFC3339 timestamp; null when unparseable or absent. */
    protected function vpnDaysUntil(?string $timestamp): ?int
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            // round, not floor: the two clock reads are microseconds apart, so
            // a credential exactly 365 days out would otherwise always render
            // as 364 — every countdown silently short by a day.
            return (int) round(now()->diffInDays(CarbonImmutable::parse($timestamp), false));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * List connected peers via the REST API — vpn:users. Null on any HTTP failure.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function listVpnPeers(string $host, string $pat): ?array
    {
        $response = NetbirdConnector::make($host, $pat)->send(ListPeersRequest::make());

        if ($response->failed()) {
            return null;
        }

        $peers = $response->json();

        return is_array($peers) ? $peers : null;
    }

    /**
     * Revoke one setup key — vpn:revoke. NetBird's PUT requires the FULL
     * object back (a partial {"revoked":true} 422s with "setup key
     * autogroups field is invalid" — empirically confirmed, undocumented),
     * so this re-sends every writable field from the list entry with
     * `revoked` flipped. expires_in is recomputed from the entry's absolute
     * `expires` since the list response never carries the original relative
     * value.
     *
     * @param  array<string, mixed>  $key  one entry from listVpnSetupKeys()
     */
    protected function revokeVpnSetupKey(string $host, string $pat, array $key): bool
    {
        $expiresIn = max(60, strtotime((string) ($key['expires'] ?? '')) - time());

        return NetbirdConnector::make($host, $pat)
            ->send(UpdateSetupKeyRequest::make($key, $expiresIn))
            ->successful();
    }

    /**
     * Resolve the NetBird VPN's access details for display.
     * Returns null when NetBird isn't installed.
     *
     * @return array{host: ?string, label: string}|null
     */
    protected function vpnAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->vpnKubectl($context);
        $ns = $this->vpnNamespace();

        if (! $this->isVpnInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveVpnHostReadOnly($env, $config),
            'label' => 'NetBird VPN',
        ];
    }

    /**
     * List registered external identity providers — SsoWireCommand/
     * SsoUnwireCommand use this to find the existing 'zitadel' entry (if
     * any) before deciding whether to POST (create) or PUT (update)/DELETE.
     * Null on any HTTP failure.
     *
     * @return array<int, array<string, mixed>>|null
     */
    /**
     * The account this token belongs to — the API never returns any other, so
     * this is a one-element list or null. Read it for `domain`: empty means SSO
     * logins cannot join it, and no API can change that.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function listVpnAccounts(string $host, string $pat): ?array
    {
        try {
            $response = NetbirdConnector::make($host, $pat)->send(ListAccountsRequest::make());

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();

            return is_array($data) ? array_values($data) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Every host currently restricted to VPN peers, read from the cluster.
     *
     * Derived from the live Ingresses rather than a list we keep, so it cannot
     * drift: `vpn:wire` and `vpn:unwire` both work by re-applying a tool's
     * ingress with or without the middleware annotation, which means the
     * annotation IS the record of what is VPN-only.
     *
     * @return list<string>
     */
    protected function vpnOnlyHosts(string $kubectl): array
    {
        $raw = Process::run("{$kubectl} get ingress -A -o json")->output();

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $hosts = [];

        foreach ($payload['items'] ?? [] as $ingress) {
            $middlewares = $ingress['metadata']['annotations']['traefik.ingress.kubernetes.io/router.middlewares'] ?? '';

            if (! str_contains((string) $middlewares, '-vpn-only@kubernetescrd')) {
                continue;
            }

            foreach ($ingress['spec']['rules'] ?? [] as $rule) {
                $host = (string) ($rule['host'] ?? '');

                if ($host !== '') {
                    $hosts[] = $host;
                }
            }
        }

        sort($hosts);

        return array_values(array_unique($hosts));
    }

    /**
     * The gateway peer's overlay address, read back from NetBird every time.
     *
     * Never cache or store this. It survives only via the client's PVC, so a
     * --purge or a lost volume re-enrols the gateway on a different address and
     * silently breaks any nameserver group still pointing at the old one
     * (observed moving 100.70.57.180 -> 100.113.100.204 across one rebuild).
     * Matching is by name prefix, never by the peer FQDN, which embeds the pod
     * hash and changes on every redeploy even when the address does not.
     */
    protected function vpnGatewayOverlayIp(string $host, string $pat, string $kubectl): ?string
    {
        $prefix = $this->vpnName('vpn-client', $kubectl);

        try {
            $response = NetbirdConnector::make($host, $pat)->send(ListPeersRequest::make());

            if ($response->failed()) {
                return null;
            }

            $fallback = null;

            foreach ((array) $response->json() as $peer) {
                if (! str_starts_with((string) ($peer['name'] ?? ''), $prefix)) {
                    continue;
                }

                $ip = trim((string) ($peer['ip'] ?? ''), '"');

                if ($ip === '') {
                    continue;
                }

                // Every rollout of the client enrols a NEW peer and orphans the
                // previous one, so the prefix routinely matches several. Only
                // the connected one can carry traffic; taking whichever came
                // first pointed split-DNS at a dead peer (live, 2026-08-30).
                if (($peer['connected'] ?? false) === true) {
                    return $ip;
                }

                $fallback ??= $ip;
            }

            return $fallback;
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Every peer in the account, or an empty list if it cannot be read.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function vpnPeers(string $host, string $pat): array
    {
        try {
            $response = NetbirdConnector::make($host, $pat)->send(ListPeersRequest::make());

            return $response->failed() ? [] : (array) $response->json();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The name of the client Pod running right now, which is also the peer name.
     *
     * NetBird names a peer after the machine's hostname, and in Kubernetes that
     * is the Pod name -- an exact identity for "the gateway that exists now",
     * with none of the ambiguity of a name prefix or a liveness flag.
     */
    protected function currentVpnGatewayPod(string $kubectl, string $ns): ?string
    {
        $app = $this->vpnName('vpn-client', $kubectl);

        $name = Process::run(
            "{$kubectl} get pods -n {$ns} -l app={$app} --field-selector=status.phase=Running "
            ."-o jsonpath='{.items[0].metadata.name}'",
        )->output();

        $name = trim($name, " '\n\r\t");

        return $name !== '' ? $name : null;
    }

    /**
     * Wait for the CURRENT gateway pod to appear as a peer, and return its address.
     *
     * Matches the running Pod's name, deliberately, rather than the `connected`
     * flag. NetBird goes on reporting a peer as connected for a while after its
     * pod is gone, so a reconcile running just after a rollout finds the
     * OUTGOING gateway, believes it is live, and writes its about-to-die address
     * into DNS. That happened twice on 2026-08-30. A pod name cannot go stale
     * that way: the peer with this name either exists or it does not.
     */
    protected function awaitVpnGateway(string $host, string $pat, string $kubectl, string $ns): ?string
    {
        $pod = $this->currentVpnGatewayPod($kubectl, $ns);

        if ($pod === null) {
            return null;
        }

        $deadline = now()->addSeconds(120);

        while (now()->lessThan($deadline)) {
            foreach ($this->vpnPeers($host, $pat) as $peer) {
                if ((string) ($peer['name'] ?? '') !== $pod) {
                    continue;
                }

                $ip = trim((string) ($peer['ip'] ?? ''), '"');

                if ($ip !== '') {
                    return $ip;
                }
            }

            // Enrolment lands a few seconds after the pod reports Running.
            Sleep::sleep(5);
        }

        return null;
    }

    /**
     * Retire gateway peers left behind by earlier rollouts.
     *
     * Identified by name: anything carrying the gateway prefix that is not the
     * Pod running right now is a corpse from a previous deploy. Matching on
     * `connected` was wrong here for the same reason it was wrong above -- a
     * peer whose pod died seconds ago still reports connected, so it would
     * survive the sweep and then be referenced as though it were live.
     *
     * @param  array<int, array<string, mixed>>  $peers
     */
    protected function pruneOrphanedVpnGateways(string $host, string $pat, array $peers, string $prefix, string $current): void
    {
        foreach ($peers as $peer) {
            $id = (string) ($peer['id'] ?? '');
            $name = (string) ($peer['name'] ?? '');

            if ($id === '' || $name === $current || ! str_starts_with($name, $prefix)) {
                continue;
            }

            try {
                NetbirdConnector::make($host, $pat)->send(DeletePeerRequest::make($id));
            } catch (Throwable) {
                // Cosmetic cleanup -- never worth failing a reconcile over.
            }
        }
    }

    /**
     * Point VPN peers at the in-cluster resolver for VPN-only hosts, and only
     * for those.
     *
     * Public DNS answers these names with the cluster's public address, so a
     * connected peer still arrives at Traefik from its ISP address and is
     * refused by the allow-list. That is what the /etc/hosts line teammates
     * have been adding works around.
     *
     * Distribution is the `All` group deliberately: SSO users never present a
     * setup key, so they never pick up `auto_groups` and land in All and
     * nothing else. Distributing to larakube-people would reach setup-key
     * peers and miss every SSO user -- the exact people this is for.
     */
    protected function reconcileVpnSplitDns(string $kubectl, string $ns, string $host, string $pat, string $env): bool
    {
        $hosts = $this->vpnOnlyHosts($kubectl);
        $existing = $this->existingVpnNameserverGroup($host, $pat);

        if ($hosts === []) {
            // Nothing is VPN-only any more. Leaving the group behind would aim
            // peers at a resolver with no records for anything.
            if ($existing !== null) {
                try {
                    NetbirdConnector::make($host, $pat)->send(DeleteNameserverGroupRequest::make($existing));
                } catch (Throwable) {
                    return false;
                }
            }

            return true;
        }

        $pod = $this->currentVpnGatewayPod($kubectl, $ns);
        $gatewayIp = $this->awaitVpnGateway($host, $pat, $kubectl, $ns);

        if ($pod === null || $gatewayIp === null) {
            return false;
        }

        $this->pruneOrphanedVpnGateways($host, $pat, $this->vpnPeers($host, $pat), $this->vpnName('vpn-client', $kubectl), $pod);

        if (! $this->applyVpnResolverConfig($kubectl, $ns, $hosts, $gatewayIp)) {
            return false;
        }

        $groupId = $this->ensureVpnGroup($host, $pat, 'All');

        try {
            $response = NetbirdConnector::make($host, $pat)->send(SaveNameserverGroupRequest::make(
                self::VPN_NAMESERVER_GROUP,
                $gatewayIp,
                self::VPN_RESOLVER_PORT,
                $hosts,
                array_values(array_filter([$groupId])),
                $existing,
            ));

            return ! $response->failed();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Re-derive split-DNS after a host's VPN status changed.
     *
     * Deliberately quiet and non-fatal: `vpn:wire` and `vpn:unwire` have both
     * already done the thing they were asked to do by the time this runs, and
     * failing them over a DNS convenience would leave the operator thinking the
     * ingress change did not happen either. A NetBird that is not installed at
     * all is not a failure here -- the middleware still stands on its own.
     */
    protected function refreshVpnSplitDns(string $kubectl, string $env): void
    {
        $ns = $this->vpnNamespace();

        if (! $this->isVpnInstalled($kubectl, $ns)) {
            return;
        }

        $config = $this->getProjectConfig();
        $host = $this->resolveVpnHostReadOnly($env, $config);
        $pat = $this->fetchVpnPat($kubectl, $ns);

        if ($host === null || $pat === null) {
            return;
        }

        if (! $this->reconcileVpnSplitDns($kubectl, $ns, $host, $pat, $env)) {
            $this->laraKubeWarn('Ingress updated, but split-DNS did not reconcile — run `larakube vpn:init '.$env.'` to retry.');
        }
    }

    /** The id of the split-DNS group if we already made one, so a re-run updates rather than stacking duplicates. */
    protected function existingVpnNameserverGroup(string $host, string $pat): ?string
    {
        try {
            $response = NetbirdConnector::make($host, $pat)->send(ListNameserverGroupsRequest::make());

            if ($response->failed()) {
                return null;
            }

            foreach ((array) $response->json() as $group) {
                if (($group['name'] ?? '') === self::VPN_NAMESERVER_GROUP) {
                    return (string) ($group['id'] ?? '') ?: null;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** Write the resolver's Corefile and restart it — CoreDNS reads the file once, at startup. */
    protected function applyVpnResolverConfig(string $kubectl, string $ns, array $hosts, string $gatewayIp): bool
    {
        $manifest = view('k8s.vpn.resolver-config', [
            'hosts' => $hosts,
            'gatewayIp' => $gatewayIp,
            'instance' => $this->vpnInstance($kubectl),
        ])->render();

        $directory = TemporaryDirectory::make();
        $path = $directory->path('larakube-vpn-resolver.yaml');
        file_put_contents($path, $manifest);

        $applied = Process::run("{$kubectl} apply -f {$path}")->successful();
        $directory->delete();

        // Deliberately no rollout restart. CoreDNS's `reload` picks the new
        // Corefile up on its own, and restarting would re-enrol the NetBird
        // client as a new peer on a new address -- invalidating the records
        // this just wrote. The kubelet takes up to a minute to project the
        // updated ConfigMap into the pod, so the change is not instant.
        return $applied;
    }

    /**
     * The email domain of the account a credential belongs to, or null.
     *
     * Empty string means an account with NO domain — created by /api/setup, and
     * unusable for SSO because single-account mode copies that emptiness onto
     * every later login. The distinction between null and '' matters here.
     */
    protected function vpnAccountDomain(string $host, string $bearer): ?string
    {
        try {
            $response = NetbirdConnector::make($host, null, $bearer)->send(ListAccountsRequest::make());

            if ($response->failed()) {
                return null;
            }

            $accounts = (array) $response->json();

            return isset($accounts[0]) ? (string) ($accounts[0]['domain'] ?? '') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Delete an account. Only ever the token's own — that is all the API permits. */
    protected function deleteVpnAccount(string $host, string $pat, string $accountId): bool
    {
        try {
            return NetbirdConnector::make($host, $pat)
                ->send(DeleteAccountRequest::make($accountId))
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    protected function listVpnIdentityProviders(string $host, string $pat): ?array
    {
        $response = NetbirdConnector::make($host, $pat)->send(ListIdentityProvidersRequest::make());

        if ($response->failed()) {
            return null;
        }

        $providers = $response->json();

        return is_array($providers) ? $providers : null;
    }

    /** Register a new external identity provider — sso:wire vpn's first-time registration. */
    protected function createVpnIdentityProvider(string $host, string $pat, string $type, string $name, string $issuer, string $clientId, string $clientSecret): bool
    {
        return NetbirdConnector::make($host, $pat)
            ->send(CreateIdentityProviderRequest::make($type, $name, $issuer, $clientId, $clientSecret))
            ->successful();
    }

    /**
     * Update an existing external identity provider by id — sso:wire vpn's
     * re-wire path. Re-sends every field, not a partial body — this
     * codebase already learned the hard way (see revokeVpnSetupKey()'s own
     * docblock) that NetBird's PUT endpoints reject partial bodies for the
     * setup-keys resource; treat identity-providers the same way rather
     * than assuming a partial update works here.
     */
    protected function updateVpnIdentityProvider(string $host, string $pat, string $id, string $type, string $name, string $issuer, string $clientId, string $clientSecret): bool
    {
        return NetbirdConnector::make($host, $pat)
            ->send(UpdateIdentityProviderRequest::make($id, $type, $name, $issuer, $clientId, $clientSecret))
            ->successful();
    }

    /** Deregister an external identity provider by id — sso:unwire vpn. */
    protected function deleteVpnIdentityProvider(string $host, string $pat, string $id): bool
    {
        return NetbirdConnector::make($host, $pat)
            ->send(DeleteIdentityProviderRequest::make($id))
            ->successful();
    }
}
