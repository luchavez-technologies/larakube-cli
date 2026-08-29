<?php

namespace App\Commands\Vpn;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Http\Integrations\Netbird\NetbirdConnector;
use App\Http\Integrations\Netbird\Requests\CreatePersonalAccessTokenRequest;
use App\Http\Integrations\Netbird\Requests\CreateServiceUserRequest;
use App\Http\Integrations\Netbird\Requests\CreateSetupKeyRequest;
use App\Http\Integrations\Netbird\Requests\ListSetupKeysRequest;
use App\Http\Integrations\Netbird\Requests\ListUsersRequest;
use App\Http\Integrations\Netbird\Requests\SetupOwnerRequest;
use App\State;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Throwable;

class VpnInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithPlex, InteractsWithProjectConfig, InteractsWithVpn, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected $signature = 'vpn:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the NetBird VPN host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for NetBird VPN (example.com → vpn.example.com; vpn.example.com used as-is)}
        {--sso-domain= : Email domain every SSO login is grouped under (defaults to the base domain of --domain)}
        {--no-plex   : Keep NetBird on its own SQLite file instead of Commons Postgres}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Deploy the cluster-wide NetBird VPN stack into larakube-vpn';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployVpn();
    }

    protected function deployVpn(): int
    {
        $ns = $this->vpnNamespace();
        $config = $this->getProjectConfig();
        $env = $this->resolveEnvironment($config);
        $kubectl = $this->vpnKubectl($this->resolveVpnContext($env, $config));

        $host = $this->resolveToolHost(SharedClusterService::VPN, ClusterTool::VPN, $env, $kubectl);

        // Resolved from $host, not the registry: on a first install vpn:init
        // renders and waits on these resources before it registers the tool.
        $mgmt = $this->vpnNameForHost('vpn-management', $host);
        $signal = $this->vpnNameForHost('vpn-signal', $host);
        $relay = $this->vpnNameForHost('vpn-relay', $host);
        $dashboard = $this->vpnNameForHost('vpn-dashboard', $host);
        $client = $this->vpnNameForHost('vpn-client', $host);
        $storeSecret = $this->vpnNameForHost('vpn-management-store', $host);

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // Must exist BEFORE the Deployments below apply — management mounts
        // management.json and relay reads NB_AUTH_SECRET from it, so without
        // this first, both would sit in CreateContainerConfigError and the
        // rollout waits below would time out.
        $configChangedOnExistingInstall = $this->ensureVpnConfig($kubectl, $ns, $host);

        // NetBird's store holds the entire control plane — accounts, peers,
        // groups, policies, setup keys, tokens. On SQLite that is one file on a
        // local-path PVC the nightly backup does not cover, so losing the node
        // loses the mesh. Commons Postgres is the default for that reason.
        $noPlex = (bool) $this->option('no-plex');
        $storeDb = '';

        if (! $noPlex) {
            $this->plexContext = $this->resolveVpnContext($env, $config);

            if (! $this->ensureCommons(['postgres'])) {
                return 1;
            }

            // Derived, never hardcoded: vpn:remove --purge drops whatever
            // commonsDatabases() computes for the registered instance, so any
            // name chosen independently here silently survives a purge.
            // DROP DATABASE IF EXISTS on a name that never existed reports
            // success, so the mismatch is invisible — confirmed live
            // 2026-08-29, where a --purge left the store fully intact and the
            // next vpn:init hit "setup already completed".
            $storeDb = ClusterTool::VPN->commonsDatabases(ClusterTool::VPN->instanceSlugFromHost($host))[0];

            $dbPassword = $this->readClusterSecretKey($kubectl, $ns, $storeSecret, 'db-password') ?? Str::random(24);

            // OpenBao-when-present. Read-only: if a past `secrets:wire --tool=vpn`
            // handed this tenant to OpenBao static-role rotation, defer to the
            // password OpenBao currently owns instead of clobbering it back to a
            // freshly generated local one — which would leave the Secret and
            // Postgres disagreeing until the next rotation. With no OpenBao (or
            // no database engine mounted) this returns the local password
            // unchanged, so --no-plex and OpenBao-less clusters are unaffected.
            $dbPassword = $this->resolveManagedDbPassword($kubectl, $storeDb, $dbPassword);

            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $storeDb, $dbPassword)) {
                return 1;
            }

            $this->registerSecret($dbPassword);
            $this->withSpin('Syncing store credentials...', fn () => Process::run(
                "{$kubectl} create secret generic {$storeSecret} -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            ));
        }

        $manifest = view('k8s.vpn.shared', [
            'host' => $host,
            'isLocal' => $env === 'local',
            'ssoDomain' => $this->vpnSsoDomain($host, $this->option('sso-domain')),
            'noPlex' => $noPlex,
            'plexNamespace' => $this->plexNamespace(),
            'storeDb' => $storeDb,
            'instance' => ClusterTool::VPN->instanceSlugFromHost($host),
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-vpn.yaml');
        file_put_contents($tmp, $manifest);

        // Three resources to verify per apply (management/signal/relay), so
        // this can't use the single apply+rollout applyAndVerifyRollout()
        // helper — every step checks its real exit code via an explicit
        // ->timeout() exceeding its own kubectl --timeout flag, or a
        // rejected apply / stuck rollout prints ✔ and this command claims
        // success regardless (confirmed live on Documenso, 2026-08-05).
        $applied = $this->withSpin('Applying NetBird VPN manifests...', fn () => Process::timeout(70)->run("{$kubectl} apply -f {$tmp} --request-timeout=60s")->successful());
        $temporaryDirectory->delete();

        if (! $applied) {
            $this->laraKubeError('Could not apply the NetBird VPN manifest — see the output above.');

            return 1;
        }

        if (! $this->withSpin('Waiting for NetBird Management...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/{$mgmt} -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError("{$mgmt} never became Ready.");

            return 1;
        }

        // Applying the Secret alone never restarts an already-running pod —
        // it just holds the OLD management.json in memory otherwise.
        if ($configChangedOnExistingInstall) {
            $this->withSpin('Restarting NetBird Management to pick up config changes...', fn () => Process::run("{$kubectl} rollout restart deployment/{$mgmt} -n {$ns}"));
            if (! $this->withSpin('Waiting for NetBird Management...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/{$mgmt} -n {$ns} --timeout=120s")->successful())) {
                $this->laraKubeError("{$mgmt} never became Ready after restarting.");

                return 1;
            }
        }

        if (! $this->withSpin('Waiting for NetBird Signal...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/{$signal} -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError("{$signal} never became Ready.");

            return 1;
        }
        if (! $this->withSpin('Waiting for NetBird Relay...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/{$relay} -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError("{$relay} never became Ready.");

            return 1;
        }
        if (! $this->withSpin('Waiting for NetBird Dashboard...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/{$dashboard} -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError("{$dashboard} never became Ready.");

            return 1;
        }

        // The client Deployment authenticates with NB_SETUP_KEY, so it can only
        // be applied AFTER bootstrapVpnAuth() mints one — applying it earlier
        // would leave it permanently unable to log in (no key to reference yet).
        // Abort, do not press on. Everything after this reaches management over
        // its hostname, so a resolver that cannot see it turns into a failed
        // bootstrap and then a gateway stuck in CreateContainerConfigError —
        // three steps removed from the actual cause, which is why this used to
        // print the right remedy and still end in the wrong error.
        if (! $this->waitForTls($kubectl, $ns, $host, $env === 'local')) {
            $this->reportStaleResolverCache($host);
            $this->newLine();
            $this->line('  <fg=gray>Nothing was rolled back — re-run</> <fg=blue>larakube vpn:init '.$env.'</> <fg=gray>once it resolves.</>');
            $this->newLine();

            return 1;
        }
        $this->bootstrapVpnAuth($kubectl, $ns, $host, $env);

        // Outside the bootstrap gate on purpose. bootstrapVpnAuth() returns
        // early once the credentials Secret exists, so anything it owns is
        // created exactly once per account lifetime — and the account can be
        // replaced under it. sso:wire retires the domain-less bootstrap account,
        // after which the operator adopts a fresh PAT with vpn:setup-key: at
        // that point the service user and both groups belong to an account that
        // no longer exists, and a re-run would have silently skipped recreating
        // them. Both helpers reuse-by-name, so this is idempotent.
        $this->ensureVpnServiceIdentity($kubectl, $ns, $host, $env);

        $clientManifest = view('k8s.vpn.client', ['instance' => ClusterTool::VPN->instanceSlugFromHost($host)])->render();
        $clientTemporaryDirectory = TemporaryDirectory::make();
        $clientTmp = $clientTemporaryDirectory->path('larakube-vpn-client.yaml');
        file_put_contents($clientTmp, $clientManifest);

        $clientRolledOut = $this->withSpin(
            'Deploying NetBird Client...',
            fn () => $this->applyAndVerifyRollout($kubectl, $clientTmp, $ns, $client, 120),
        );
        $clientTemporaryDirectory->delete();

        // Register BEFORE reporting the gateway's outcome. The tool is installed
        // the moment its manifests are applied and the servers are Ready — a
        // gateway pod that has not settled is a degraded install, not an absent
        // one. Gating registration on it meant every failed run left VPN
        // unregistered, so the next `vpn:remove --purge` resolved NO instance,
        // computed the unsuffixed tenant name, and ran DROP DATABASE IF EXISTS
        // against a name that never existed — reporting success while the real
        // database survived untouched. Confirmed live 2026-08-29, twice.
        $this->registerDeployedTool(ClusterTool::VPN, $kubectl, $host);

        if (! $clientRolledOut) {
            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ NetBird VPN stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>NetBird Admin URL:</>            <fg=blue>https://{$host}</>");

        // The dashboard logs in against the EMBEDDED IdP, so this is the
        // credential that actually opens it — print it like mail:init does,
        // rather than leaving the operator with a URL and no way in.
        $adminEmail = $this->readClusterSecretKey($kubectl, $ns, $this->vpnNameForHost('vpn-management-secrets', $host), 'admin-email');
        $adminPassword = $this->readClusterSecretKey($kubectl, $ns, $this->vpnNameForHost('vpn-management-secrets', $host), 'admin-password');

        if ($adminEmail !== null && $adminPassword !== null) {
            $this->line("  <fg=gray>Dashboard login:</>              <fg=blue>{$adminEmail}</> / <fg=blue>{$adminPassword}</>");
        } else {
            // Pre-2026-08-28 installs discarded the generated password.
            $this->line('  <fg=gray>Dashboard login:</>              <fg=yellow>not stored by this install</> — set one with:');
            $this->line("    <fg=blue>kubectl exec deploy/{$mgmt} -n {$ns} -- \\</>");
            $this->line('    <fg=blue>  /go/bin/netbird-mgmt admin user change-password --email &lt;you@domain&gt; --password &lt;new&gt;</>');
        }

        // The stack can be entirely healthy and still be unusable for the next
        // teammate: with single-account mode off, every SSO login mints its own
        // account with its own /16 and no route to the gateway just deployed.
        // The invariant is silent in both directions, so report it here — the
        // one moment the startup log is guaranteed to be fresh.
        //
        // A warning, not a failure: the deploy really did succeed, and failing
        // would make vpn:init un-runnable on the very cluster that needs fixing.
        $singleAccount = $this->vpnSingleAccountState($kubectl, $ns);

        if ($singleAccount !== null && ! $singleAccount['enabled']) {
            $this->newLine();
            // laraKubeWarn() renders through termwind, which wraps to the
            // terminal width — keep the number out of it so it stays greppable.
            $this->laraKubeWarn('Single-account mode is OFF.');
            $this->line("  <fg=yellow>Management counted {$singleAccount['accounts']} accounts.</>");
            $this->line('  <fg=gray>Each new SSO login will land in its own account, with its own /16 and no route</>');
            $this->line('  <fg=gray>to this cluster. NetBird re-checks the count on every restart, so this clears</>');
            $this->line('  <fg=gray>itself once one account remains — but nothing lowers the count on its own, and</>');
            $this->line('  <fg=gray>there is no API or admin-CLI path to remove the extras.</>');
        }

        $this->newLine();

        return 0;
    }

    /**
     * The relay's shared auth secret + management.json (Signal/Relay wired to
     * this install's EXTERNAL host, since peers dial them directly over the
     * public Ingress, not the in-cluster DNS names the other env vars in
     * shared.blade.php use) — both hold a real secret, so this is a k8s
     * Secret, not a ConfigMap.
     *
     * Re-runs read BOTH the relay secret and the data-store encryption key
     * back from the existing management.json and re-render the template
     * with those SAME values — never regenerate them. Confirmed live
     * 2026-08-25: dataStoreEncryptionKey doubles as EmbeddedIdP's own
     * database encryption key; a naive "skip entirely if the secret
     * exists" (the original design) meant a genuine template fix (e.g. the
     * /oauth2 issuer suffix below) could never reach an already-deployed
     * cluster without deleting the secret by hand — and deleting it would
     * silently mint a FRESH random key, making the already-encrypted
     * management database unreadable on next boot. This mirrors
     * renderMasConfig()'s strip-and-reapply idiom: only genuinely fresh
     * installs mint new secrets, every re-run preserves them while still
     * picking up structural template changes.
     *
     * @return bool whether an ALREADY-DEPLOYED management Deployment needs a
     *              restart to pick up a real content change — applying the
     *              Secret alone never triggers one (no config-checksum
     *              annotation ties the Deployment to it). Always false on a
     *              genuinely fresh install: there's no existing Deployment
     *              to restart yet, the one about to be created reads the
     *              current Secret content from the start.
     */
    protected function ensureVpnConfig(string $kubectl, string $ns, string $host): bool
    {
        $existingRaw = trim(Process::run(
            "{$kubectl} get secret ".$this->vpnNameForHost('vpn-management-config', $host)." -n {$ns} -o jsonpath='{.data.management\.json}'",
        )->output());
        $existingConfig = $existingRaw !== '' ? (string) base64_decode($existingRaw) : null;
        $existingDecoded = $existingConfig !== null ? json_decode($existingConfig, true) : null;

        $relaySecret = is_array($existingDecoded) ? ($existingDecoded['Relay']['Secret'] ?? null) : null;
        $dataStoreEncryptionKey = is_array($existingDecoded) ? ($existingDecoded['DataStoreEncryptionKey'] ?? null) : null;

        $isFreshInstall = $relaySecret === null || $dataStoreEncryptionKey === null;
        if ($isFreshInstall) {
            // management.json is mounted from a Secret via subPath, which k8s
            // always mounts read-only — so this key must be baked in up front.
            // Without it, management tries to generate + write one back
            // to the file on first boot and crashloops on "read-only file system".
            // Also doubles as EmbeddedIdP's EncryptionKey below — without that
            // block (undocumented in NetBird's own automated-setup guide; found
            // by trial and error), POST /api/setup fails with "embedded IDP is
            // not enabled".
            $relaySecret = bin2hex(random_bytes(16));
            $this->registerSecret($relaySecret);
            $dataStoreEncryptionKey = base64_encode(random_bytes(32));
            $this->registerSecret($dataStoreEncryptionKey);
        }

        $managementConfig = view('k8s.vpn.management-config', [
            'host' => $host,
            'relaySecret' => $relaySecret,
            'dataStoreEncryptionKey' => $dataStoreEncryptionKey,
        ])->render();

        if (! $isFreshInstall && $existingConfig !== null && trim($existingConfig) === trim($managementConfig)) {
            return false;
        }

        $changed = false;
        $configSecret = $this->vpnNameForHost('vpn-management-config', $host);
        $this->withSpin('Preparing NetBird relay config...', function () use (&$changed, $kubectl, $ns, $relaySecret, $managementConfig, $configSecret): void {
            $temporaryDirectory = TemporaryDirectory::make();
            $tmp = $temporaryDirectory->path('larakube-vpn-management.json');
            file_put_contents($tmp, $managementConfig);

            $changed = Process::run(
                "{$kubectl} create secret generic {$configSecret} -n {$ns} "
                .'--from-literal=relay-secret='.escapeshellarg($relaySecret).' '
                .'--from-file=management.json='.escapeshellarg($tmp).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            )->successful();
            $temporaryDirectory->delete();
        });

        return $changed && ! $isFreshInstall;
    }

    /**
     * Bootstrap NetBird auth with zero browser/dashboard interaction: create the
     * first owner user + a Personal Access Token via NB_SETUP_PAT_ENABLED's
     * POST /api/setup, then mint one reusable setup key from it. Both are stored
     * as a k8s Secret (same create|apply pattern as ConfigData::backupToCluster())
     * so any teammate with kubectl access to this cluster can fetch the setup key
     * for `vpn:join` / `cloud:harden` — no separate secret-sharing channel needed.
     * Idempotent: skipped entirely once the Secret exists, since re-POSTing
     * /api/setup against an already-bootstrapped instance would just fail.
     */
    protected function bootstrapVpnAuth(string $kubectl, string $ns, string $host, string $env): void
    {
        if (Process::run("{$kubectl} get secret ".$this->vpnNameForHost('vpn-management-secrets', $host)." -n {$ns}")->successful()) {
            return;
        }

        $this->withSpin('Bootstrapping NetBird auth (no dashboard login needed)...', function () use ($kubectl, $ns, $host, $env): void {
            // Persisted below alongside the PAT. This is the ONLY copy: it is
            // the embedded IdP owner's password, and the dashboard logs in
            // against that IdP — so discarding it (as this did until
            // 2026-08-28) leaves the dashboard with no usable login at all and
            // recovery needs `netbird-mgmt admin user change-password` inside
            // the pod.
            $password = bin2hex(random_bytes(16));

            // Deliberately NOT the operator's own address. NetBird claims an
            // account's private domain from the owner's email on login
            // (updateAccountDomainAttributesIfNotUpToDate), and it can only ever
            // claim a domain the cluster actually owns — a personal gmail.com
            // address is a public domain and leaves the account with none, which
            // is what breaks single-account mode later. Nor "admin@{$host}":
            // that is vpn.example.com, a subdomain of the domain SSO logins
            // will carry, so it would claim the wrong one.
            $email = "admin@{$this->vpnSsoDomain($host, $this->option('sso-domain'))}";

            // Retry the /api/setup POST — the TLS wait above confirms the cert
            // is valid, but the management pod may still need a moment to
            // accept connections through the Ingress.
            // Wait for the endpoint to answer at all before spending the setup
            // retry budget on it. TLS being valid does not mean management is
            // serving yet, and burning six attempts in 30s against a host that
            // is still coming up is what left the credentials Secret unwritten —
            // which in turn leaves the gateway in CreateContainerConfigError
            // with no indication that bootstrap was the thing that failed.
            $this->waitForVpnEndpoint($host, 180);

            $setup = null;
            $maxAttempts = (int) 6;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $setup = NetbirdConnector::make($host)->send(SetupOwnerRequest::make($email, 'larakube', $password, 365));
                    break;
                } catch (FatalRequestException $e) {
                    if ($attempt === $maxAttempts || State::$isTesting) {
                        $this->laraKubeWarn('Could not reach NetBird management after multiple attempts — run `larakube vpn:init` again once the endpoint is reachable.');

                        return;
                    }
                    Sleep::sleep(5);
                }
            }

            $pat = $setup?->json('personal_access_token');

            // 412 means the STORE already has an owner, which is a different
            // problem from a half-finished install: it happens when the
            // namespace was rebuilt but the Commons tenant survived, because
            // plain vpn:remove drops the namespace and leaves the database.
            // /api/setup can never succeed again against that store, so
            // "log in once to finish setup" is precisely the wrong advice.
            if ($setup !== null && $setup->status() === 412) {
                $this->laraKubeWarn('NetBird already has an owner in its database — this cluster cannot re-bootstrap.');
                $this->line('  <fg=gray>The namespace was recreated but the Commons store survived (plain vpn:remove keeps it).</>');
                $this->newLine();
                $this->line('  <fg=gray>Start clean — drops the database too:</>');
                $this->line('  <fg=blue>  larakube vpn:remove '.$env.' --purge</> <fg=gray>then</> <fg=blue>larakube vpn:init '.$env.'</>');
                $this->newLine();
                $this->line('  <fg=gray>Or keep the existing account: mint a PAT in the dashboard</>');
                $this->line('  <fg=gray>(Team → Users → your user → Access Tokens), then</>');
                $this->line('  <fg=blue>  larakube vpn:setup-key '.$env.' --pat=…</>');

                return;
            }

            if (! $setup || $setup->failed() || ! $pat) {
                $this->laraKubeWarn('Could not bootstrap NetBird auth automatically — log into the dashboard once to finish setup.');

                return;
            }
            $this->registerSecret($pat);

            // Move off the owner's token immediately. /api/setup can only mint a
            // PAT for the human it just created, so the owner PAT is used once —
            // to create a service user and mint that user's token — and then
            // never stored. Delete the human later and the CLI keeps working.
            $ownerPat = $pat;
            $pat = $this->adoptVpnServiceUserPat($host, $pat);

            // Groups first: a peer can only be placed at enrolment, so the
            // gateway has to have somewhere to land before its key is minted.
            $groups = $this->ensureVpnBaseGroups($host, $pat);
            $routers = array_values(array_filter([$groups['routers']]));

            // 1 year — matches the PAT's own 365-day cap above, so both need
            // renewing around the same time (a known follow-up, not handled here).
            // Minted with whichever PAT we settled on above, so a service-user
            // token that cannot actually mint keys fails here, before it is stored.
            $setupKey = NetbirdConnector::make($host, $pat)->send(CreateSetupKeyRequest::make('larakube', 31536000, 0, false, $routers));

            $key = $setupKey->json('key');
            if ($setupKey->failed() || ! $key) {
                $this->laraKubeWarn('NetBird owner created, but minting a setup key failed — create one manually in the dashboard for `vpn:join`.');

                return;
            }
            $this->registerSecret($key);
            $this->registerSecret($password);

            $this->seedVpnPatIntoOpenBao($kubectl, $host, $pat, $env);

            Process::run(
                "{$kubectl} create secret generic ".$this->vpnNameForHost('vpn-management-secrets', $host)." -n {$ns} "
                .'--from-literal=pat='.escapeshellarg($pat).' '
                // The owner's own token, kept ONLY because NetBird restricts a
                // few actions to the account owner and refuses them to an admin
                // service user — deleting the account among them, which is what
                // sso:wire's retire needs. Discarding it left that step
                // impossible to perform through the API at all (403, confirmed
                // live 2026-08-29). Everything routine still uses `pat`.
                .'--from-literal=owner-pat='.escapeshellarg($ownerPat).' '
                .'--from-literal=setup-key='.escapeshellarg($key).' '
                .'--from-literal=admin-email='.escapeshellarg($email).' '
                .'--from-literal=admin-password='.escapeshellarg($password).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });
    }

    /**
     * Ensure the stored PAT belongs to the larakube-cli service user, and that
     * both cluster-level groups exist, whatever account is current.
     *
     * Quiet no-op on the normal path: after a first vpn:init the PAT already
     * belongs to the service user and the groups already exist, so every call
     * here is a lookup that finds what it wanted.
     */
    protected function ensureVpnServiceIdentity(string $kubectl, string $ns, string $host, string $env): void
    {
        $pat = $this->fetchVpnPat($kubectl, $ns);

        if ($pat === null) {
            return;
        }

        $this->withSpin('Ensuring the CLI service user and groups...', function () use ($kubectl, $ns, $host, $env, $pat): void {
            $serviceUserId = $this->existingVpnServiceUserId($host, $pat);
            $currentUserId = $this->vpnCurrentUserId($host, $pat);

            // A PAT that is not the service user's — the state right after
            // adopting one minted by hand in the dashboard. Move off it.
            if ($currentUserId !== null && $currentUserId !== $serviceUserId) {
                $adopted = $this->adoptVpnServiceUserPat($host, $pat);

                if ($adopted !== $pat) {
                    $this->persistVpnPat($kubectl, $adopted, $env);
                    $pat = $adopted;
                }
            }

            $groups = $this->ensureVpnBaseGroups($host, $pat);

            $this->ensureVpnGatewayKey($kubectl, $ns, $host, $pat, $groups);
        });
    }

    /**
     * Ensure a gateway setup key exists and is the one the client Deployment holds.
     *
     * This used to live inside the /api/setup bootstrap block, which meant it only
     * ever ran on the very first vpn:init. Once vpn:sso-login started creating the
     * account instead, bootstrap short-circuits and no key was ever minted — the
     * client came up, failed with "no peer auth method provided", and vpn:init
     * still printed a tick because it only ever checked the rollout. Confirmed
     * live 2026-08-29: 0 setup keys, 0 peers, a healthy-looking 2/2 pod.
     *
     * Reuse before mint: setup keys are not secret-bearing after creation (the API
     * never returns `key` again), so a re-run cannot re-read an existing one — it
     * has to trust that the key already in the Secret matches the one the API
     * lists, and only mint when the API has none valid at all.
     */
    protected function ensureVpnGatewayKey(string $kubectl, string $ns, string $host, string $pat, array $groups): void
    {
        $secret = $this->vpnNameForHost('vpn-management-secrets', $host);
        $routers = array_values(array_filter([$groups['routers'] ?? null]));

        try {
            $existing = NetbirdConnector::make($host, $pat)->send(ListSetupKeysRequest::make());

            if ($existing->failed()) {
                return;
            }

            foreach ((array) $existing->json() as $key) {
                if (($key['valid'] ?? false) === true && ($key['revoked'] ?? false) === false) {
                    return;
                }
            }

            $created = NetbirdConnector::make($host, $pat)
                ->send(CreateSetupKeyRequest::make('larakube', 31536000, 0, false, $routers));

            $plain = $created->json('key');

            if ($created->failed() || ! is_string($plain) || $plain === '') {
                $this->laraKubeWarn('Could not mint a NetBird gateway setup key — the in-cluster peer will not enrol.');

                return;
            }

            $this->registerSecret($plain);

            Process::run(
                "{$kubectl} patch secret {$secret} -n {$ns} --type=merge -p "
                .escapeshellarg((string) json_encode(['data' => ['setup-key' => base64_encode($plain)]], JSON_THROW_ON_ERROR)),
            );

            // The client only reads NB_SETUP_KEY at startup, so a fresh key in the
            // Secret means nothing until the pod restarts.
            Process::run("{$kubectl} rollout restart deploy/".$this->vpnNameForHost('vpn-client', $host)." -n {$ns}");
        } catch (Throwable) {
            $this->laraKubeWarn('Could not verify the NetBird gateway setup key.');
        }
    }

    /**
     * Swap a freshly-bootstrapped owner PAT for one belonging to a dedicated
     * service user, returning whichever token the caller should actually store.
     *
     * Best-effort by design: a NetBird that will not create the service user is
     * still a working NetBird, and failing vpn:init over it would leave the
     * cluster with no VPN at all rather than one with a slightly worse token.
     * The warning says which of the two you ended up with.
     */
    protected function adoptVpnServiceUserPat(string $host, string $ownerPat): string
    {
        try {
            // Reuse before create. /api/setup gates this whole method and only
            // succeeds once per account, so a duplicate is hard to reach — but a
            // retry that got this far already has a larakube-cli sitting there,
            // and two identically-named service users is a confusing thing to
            // leave in someone's dashboard forever.
            $userId = $this->existingVpnServiceUserId($host, $ownerPat);

            if ($userId === null) {
                $user = NetbirdConnector::make($host, $ownerPat)
                    ->send(CreateServiceUserRequest::make('larakube-cli'));

                $userId = $user->json('id');

                if ($user->failed() || ! $userId) {
                    throw new RuntimeException('service user not created');
                }
            }

            $token = NetbirdConnector::make($host, $ownerPat)
                ->send(CreatePersonalAccessTokenRequest::make($userId, 'larakube', 365));

            $servicePat = $token->json('plain_token');

            if ($token->failed() || ! $servicePat) {
                throw new RuntimeException('service user token not minted');
            }

            $this->registerSecret($servicePat);

            return $servicePat;
        } catch (Throwable) {
            $this->laraKubeWarn('Could not create a NetBird service user — storing the owner\'s token instead.');
            $this->line('  <fg=gray>Works today, but it dies with that user. Re-run vpn:init after fixing to move off it.</>');

            return $ownerPat;
        }
    }

    /**
     * The id of an existing `larakube-cli` service user, or null. Never throws:
     * a failed lookup just means "create one", which the caller handles.
     */
    protected function existingVpnServiceUserId(string $host, string $pat): ?string
    {
        try {
            $users = NetbirdConnector::make($host, $pat)->send(ListUsersRequest::make());

            if ($users->failed()) {
                return null;
            }

            foreach ($users->json() ?? [] as $user) {
                if (($user['is_service_user'] ?? false) && ($user['name'] ?? null) === 'larakube-cli') {
                    return $user['id'] ?? null;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Seed the PAT into OpenBao KV so it can later be rotated without the CLI.
     *
     * VpnTool declares a KV sync for this key, which means secrets:init creates
     * an ExternalSecret reading `{env}/VPN_{SLUG}_PAT`. With `creationPolicy:
     * Merge` an unpopulated key leaves the Secret's own value alone but parks the
     * ExternalSecret at SecretMissing forever — the same red noise this cluster
     * already carries from data-secrets-db and link-kutt-secrets-db. Writing the
     * value we just minted means it is green from the first install, and pasting
     * a new PAT into OpenBao is then a real rotation path.
     *
     * Best-effort: no OpenBao on the cluster is the normal case for a small
     * install, and it must not fail the deploy.
     */
    protected function seedVpnPatIntoOpenBao(string $kubectl, string $host, string $pat, string $env): void
    {
        $keyMap = ClusterTool::VPN->openbaoSyncConfig(ClusterTool::VPN->instanceSlugFromHost($host))['keyMap'] ?? [];
        $kvKey = array_key_first($keyMap);

        if ($kvKey === null || ! $this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
            return;
        }

        $this->pushClusterSecret($kubectl, $kvKey, $pat, $env === 'local' ? 'local' : 'production');
    }

    /**
     * Wait for TLS to become valid on the VPN host — Traefik's ACME resolver
     * needs a few seconds after the Ingress is created to complete the Let's
     * Encrypt challenge. Without this gate, bootstrapVpnAuth() would fire an
     * HTTPS call against a self-signed/missing cert and crash with cURL error 60.
     *
     * On a cloud env, a brand-new host races ExternalDNS's own sync cycle
     * against Traefik's FIRST ACME attempt (triggered the instant the Ingress
     * is applied) — confirmed live 2026-08-24: that first attempt hit
     * NXDOMAIN because the DNS record hadn't propagated yet, and Traefik's
     * Lego ACME client then backed off for ~2 HOURS before retrying on its
     * own. Simply waiting longer here would mean blocking the CLI for that
     * long, which is never acceptable. Instead: if the short poll below
     * doesn't see a valid cert, wait for DNS to actually propagate (via
     * public resolvers directly — bypassing any local machine's own
     * negative-cached NXDOMAIN, confirmed to happen on 2026-08-24 too), then
     * force a FRESH ACME attempt by deleting+recreating the Ingress. Traefik
     * treats that as a brand-new router and retries immediately rather than
     * respecting the earlier attempt's backoff.
     */
    protected function waitForTls(string $kubectl, string $ns, string $host, bool $isLocal): bool
    {
        if (State::$isTesting) {
            return true;
        }

        // Local envs never use Let's Encrypt (see ingress.blade.php's
        // @unless($isLocal) guard) — LaraKube's own local CA is trusted
        // immediately, nothing to wait for or retry.
        if ($isLocal) {
            return true;
        }

        $resolvable = true;

        $this->withSpin('Waiting for TLS certificate (Let\'s Encrypt)...', function () use ($kubectl, $ns, $host, $isLocal, &$resolvable): void {
            if ($this->pollForValidTls($host, 90)) {
                return;
            }

            // Nudge only if DNS is genuinely missing, and never before the first
            // poll: restarting the controller takes it offline for the very
            // window that is waiting on it, which turns a slow reconcile into a
            // guaranteed one.
            if (! $this->pollForDnsPropagation($host, 45)) {
                $this->nudgeExternalDns($kubectl);
            }

            if (! $this->pollForDnsPropagation($host, 90)) {
                $this->laraKubeWarn("DNS for {$host} hasn't propagated after 90s — proceeding anyway (auth bootstrap may fail; re-run `vpn:init` once DNS resolves).");

                return;
            }

            // Public DNS is live but the TLS probe still failed. Before blaming
            // ACME, rule out the one cause a fresh certificate cannot fix: this
            // machine being unable to resolve the name at all.
            if (! $this->hostResolvesLocally($host)) {
                $resolvable = false;

                return;
            }

            $this->forceFreshAcmeAttempt($kubectl, $ns, $host, $isLocal);

            if ($this->pollForValidTls($host, 90)) {
                return;
            }

            $this->laraKubeWarn('TLS still not ready after DNS propagated and a forced retry — proceeding anyway (auth bootstrap may fail; re-run `vpn:init` if it does).');
        });

        return $resolvable;
    }

    /**
     * Poll until `https://$host` presents a browser-trusted cert, or the
     * deadline passes. Deliberately NOT `curl -f`: confirmed live 2026-08-24
     * this check ran against NetBird Management's root path, which returns a
     * legitimate HTTP 404 regardless of certificate validity (Management has
     * no real page at `/`) — `-f` treats that 404 as a curl failure, so this
     * check could never succeed even once TLS was genuinely fine, forcing
     * every run through the DNS-wait/forced-retry path below for no reason
     * and always ending in a scary-looking (but harmless) warning. Without
     * `-f`, curl only fails on a real connection/TLS error — an HTTP error
     * status still completing the TLS handshake counts as success here,
     * which is all this check needs to know.
     */
    protected function pollForValidTls(string $host, int $maxWait): bool
    {
        $start = time();
        while (time() - $start < $maxWait) {
            if (Process::run('curl -sS -o /dev/null '.escapeshellarg("https://{$host}"))->successful()) {
                return true;
            }
            Sleep::sleep(5);
        }

        return false;
    }

    /**
     * Poll until $host answers anything at all — any HTTP status means management
     * is serving. Returns whether it came up; the caller proceeds either way so a
     * slow cluster degrades to the existing retry rather than a hard failure.
     */
    protected function waitForVpnEndpoint(string $host, int $maxWait): bool
    {
        if (State::$isTesting) {
            return true;
        }

        $deadline = now()->addSeconds($maxWait);

        while (now()->lessThan($deadline)) {
            $code = trim(Process::timeout(20)->run(
                'curl -s -o /dev/null -w '.escapeshellarg('%{http_code}')
                .' --max-time 15 '.escapeshellarg("https://{$host}/api/users"),
            )->output());

            if ($code !== '' && $code !== '000') {
                return true;
            }

            Sleep::sleep(5);
        }

        return false;
    }

    /**
     * Force ExternalDNS to reconcile immediately rather than on its next poll.
     *
     * Best-effort and deliberately quiet: a cluster may run several zone
     * deployments or none at all, and none of that should fail a deploy. The
     * label is what dns:init gives every zone controller it creates.
     */
    protected function nudgeExternalDns(string $kubectl): void
    {
        $deployments = trim(Process::run(
            "{$kubectl} get deploy -A -l app.kubernetes.io/name=external-dns -o name --no-headers",
        )->output());

        if ($deployments === '') {
            return;
        }

        Process::run("{$kubectl} rollout restart -A -l app.kubernetes.io/name=external-dns deployment");
    }

    /**
     * Poll multiple PUBLIC resolvers directly (not this machine's own
     * resolver, which may hold a stale negative-cached NXDOMAIN from before
     * the record existed — confirmed live 2026-08-24) until the host
     * resolves to something on all of them.
     */
    protected function pollForDnsPropagation(string $host, int $maxWait): bool
    {
        $resolvers = ['1.1.1.1', '8.8.8.8'];
        $start = time();

        while (time() - $start < $maxWait) {
            $allResolved = true;
            foreach ($resolvers as $resolver) {
                $answer = trim(Process::run('dig +short +time=3 +tries=1 '.escapeshellarg($host)." @{$resolver}")->output());
                if ($answer === '') {
                    $allResolved = false;
                    break;
                }
            }
            if ($allResolved) {
                return true;
            }
            Sleep::sleep(5);
        }

        return false;
    }

    /**
     * Delete + re-apply just the Ingress so Traefik treats it as a brand-new
     * router and attempts ACME immediately, instead of respecting the long
     * backoff from its earlier failed attempt.
     */
    protected function forceFreshAcmeAttempt(string $kubectl, string $ns, string $host, bool $isLocal): void
    {
        $manifest = view('k8s.vpn.ingress', ['host' => $host, 'isLocal' => $isLocal, 'instance' => ClusterTool::VPN->instanceSlugFromHost($host)])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-vpn-ingress-retry.yaml');
        file_put_contents($tmp, $manifest);

        Process::run("{$kubectl} delete ingress ".$this->vpnNameForHost('vpn-management', $host)." -n {$ns} --ignore-not-found");
        Process::run("{$kubectl} apply -f {$tmp}");

        $temporaryDirectory->delete();
    }

    /**
     * Decide which environment this install targets.
     */
    protected function resolveEnvironment(?ConfigData $config = null): string
    {
        return $this->resolveToolEnvironment(ClusterTool::VPN, $config);
    }
}
