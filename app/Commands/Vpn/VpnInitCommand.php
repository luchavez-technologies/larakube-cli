<?php

namespace App\Commands\Vpn;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Http\Integrations\Netbird\NetbirdConnector;
use App\Http\Integrations\Netbird\Requests\CreateSetupKeyRequest;
use App\Http\Integrations\Netbird\Requests\SetupOwnerRequest;
use App\State;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use Saloon\Exceptions\Request\FatalRequestException;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class VpnInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithProjectConfig, InteractsWithVpn, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

    protected $signature = 'vpn:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the NetBird VPN host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for NetBird VPN (example.com → vpn.example.com; vpn.example.com used as-is)}
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

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // Must exist BEFORE the Deployments below apply — management mounts
        // management.json and relay reads NB_AUTH_SECRET from it, so without
        // this first, both would sit in CreateContainerConfigError and the
        // rollout waits below would time out.
        $configChangedOnExistingInstall = $this->ensureVpnConfig($kubectl, $ns, $host);

        $manifest = view('k8s.vpn.shared', [
            'host' => $host,
            'isLocal' => $env === 'local',
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

        if (! $this->withSpin('Waiting for NetBird Management...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/netbird-management -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('netbird-management never became Ready.');

            return 1;
        }

        // Applying the Secret alone never restarts an already-running pod —
        // it just holds the OLD management.json in memory otherwise.
        if ($configChangedOnExistingInstall) {
            $this->withSpin('Restarting NetBird Management to pick up config changes...', fn () => Process::run("{$kubectl} rollout restart deployment/netbird-management -n {$ns}"));
            if (! $this->withSpin('Waiting for NetBird Management...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/netbird-management -n {$ns} --timeout=120s")->successful())) {
                $this->laraKubeError('netbird-management never became Ready after restarting.');

                return 1;
            }
        }

        if (! $this->withSpin('Waiting for NetBird Signal...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/netbird-signal -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('netbird-signal never became Ready.');

            return 1;
        }
        if (! $this->withSpin('Waiting for NetBird Relay...', fn () => Process::timeout(130)->run("{$kubectl} rollout status deploy/netbird-relay -n {$ns} --timeout=120s")->successful())) {
            $this->laraKubeError('netbird-relay never became Ready.');

            return 1;
        }

        // The client Deployment authenticates with NB_SETUP_KEY, so it can only
        // be applied AFTER bootstrapVpnAuth() mints one — applying it earlier
        // would leave it permanently unable to log in (no key to reference yet).
        $this->waitForTls($kubectl, $ns, $host, $env === 'local');
        $this->bootstrapVpnAuth($kubectl, $ns, $host);

        $clientManifest = view('k8s.vpn.client')->render();
        $clientTemporaryDirectory = TemporaryDirectory::make();
        $clientTmp = $clientTemporaryDirectory->path('larakube-vpn-client.yaml');
        file_put_contents($clientTmp, $clientManifest);

        $clientRolledOut = $this->withSpin(
            'Deploying NetBird Client...',
            fn () => $this->applyAndVerifyRollout($kubectl, $clientTmp, $ns, 'netbird-client', 120),
        );
        $clientTemporaryDirectory->delete();

        if (! $clientRolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::VPN, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ NetBird VPN stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>NetBird Admin URL:</>            <fg=blue>https://{$host}</>");
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
     * @return bool whether an ALREADY-DEPLOYED netbird-management needs a
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
            "{$kubectl} get secret netbird-relay-secret -n {$ns} -o jsonpath='{.data.management\.json}'",
        )->output());
        $existingConfig = $existingRaw !== '' ? (string) base64_decode($existingRaw) : null;
        $existingDecoded = $existingConfig !== null ? json_decode($existingConfig, true) : null;

        $relaySecret = is_array($existingDecoded) ? ($existingDecoded['Relay']['Secret'] ?? null) : null;
        $dataStoreEncryptionKey = is_array($existingDecoded) ? ($existingDecoded['DataStoreEncryptionKey'] ?? null) : null;

        $isFreshInstall = $relaySecret === null || $dataStoreEncryptionKey === null;
        if ($isFreshInstall) {
            // management.json is mounted from a Secret via subPath, which k8s
            // always mounts read-only — so this key must be baked in up front.
            // Without it, netbird-management tries to generate + write one back
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
        $this->withSpin('Preparing NetBird relay config...', function () use (&$changed, $kubectl, $ns, $relaySecret, $managementConfig): void {
            $temporaryDirectory = TemporaryDirectory::make();
            $tmp = $temporaryDirectory->path('larakube-vpn-management.json');
            file_put_contents($tmp, $managementConfig);

            $changed = Process::run(
                "{$kubectl} create secret generic netbird-relay-secret -n {$ns} "
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
    protected function bootstrapVpnAuth(string $kubectl, string $ns, string $host): void
    {
        if (Process::run("{$kubectl} get secret vpn-secrets -n {$ns}")->successful()) {
            return;
        }

        $this->withSpin('Bootstrapping NetBird auth (no dashboard login needed)...', function () use ($kubectl, $ns, $host): void {
            $password = bin2hex(random_bytes(16));
            $email = $this->getEmail() ?: "admin@{$host}";

            // Retry the /api/setup POST — the TLS wait above confirms the cert
            // is valid, but the management pod may still need a moment to
            // accept connections through the Ingress.
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
            if (! $setup || $setup->failed() || ! $pat) {
                $this->laraKubeWarn('Could not bootstrap NetBird auth automatically — log into the dashboard once to finish setup.');

                return;
            }
            $this->registerSecret($pat);

            // 1 year — matches the PAT's own 365-day cap above, so both need
            // renewing around the same time (a known follow-up, not handled here).
            $setupKey = NetbirdConnector::make($host, $pat)->send(CreateSetupKeyRequest::make('larakube', 31536000, 0));

            $key = $setupKey->json('key');
            if ($setupKey->failed() || ! $key) {
                $this->laraKubeWarn('NetBird owner created, but minting a setup key failed — create one manually in the dashboard for `vpn:join`.');

                return;
            }
            $this->registerSecret($key);

            Process::run(
                "{$kubectl} create secret generic vpn-secrets -n {$ns} "
                .'--from-literal=pat='.escapeshellarg($pat).' '
                .'--from-literal=setup-key='.escapeshellarg($key).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });
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
    protected function waitForTls(string $kubectl, string $ns, string $host, bool $isLocal): void
    {
        if (State::$isTesting) {
            return;
        }

        // Local envs never use Let's Encrypt (see ingress.blade.php's
        // @unless($isLocal) guard) — LaraKube's own local CA is trusted
        // immediately, nothing to wait for or retry.
        if ($isLocal) {
            return;
        }

        $this->withSpin('Waiting for TLS certificate (Let\'s Encrypt)...', function () use ($kubectl, $ns, $host, $isLocal): void {
            if ($this->pollForValidTls($host, 90)) {
                return;
            }

            if (! $this->pollForDnsPropagation($host, 90)) {
                $this->laraKubeWarn("DNS for {$host} hasn't propagated after 90s — proceeding anyway (auth bootstrap may fail; re-run `vpn:init` once DNS resolves).");

                return;
            }

            $this->forceFreshAcmeAttempt($kubectl, $ns, $host, $isLocal);

            if ($this->pollForValidTls($host, 90)) {
                return;
            }

            $this->laraKubeWarn('TLS still not ready after DNS propagated and a forced retry — proceeding anyway (auth bootstrap may fail; re-run `vpn:init` if it does).');
        });
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
        $manifest = view('k8s.vpn.ingress', ['host' => $host, 'isLocal' => $isLocal])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-vpn-ingress-retry.yaml');
        file_put_contents($tmp, $manifest);

        Process::run("{$kubectl} delete ingress netbird-management -n {$ns} --ignore-not-found");
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
