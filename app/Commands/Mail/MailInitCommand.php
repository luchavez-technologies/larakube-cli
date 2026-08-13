<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\InteractsWithSecrets;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCloudFirewall;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class MailInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithMail, InteractsWithPlex, InteractsWithRemoteSsh, InteractsWithSecrets, InteractsWithStalwartApi, InteractsWithTraefik, LaraKubeOutput, ManagesCloudFirewall, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected $signature = 'mail:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Stalwart (example.com → prefix.example.com)}
        {--alias=*    : Additional domain alias(es) to register on the Ingress}
        {--instance=main : Named instance identifier (default: main)}
        {--vpn-only  : Restrict the admin UI via NetBird VPN IP whitelisting}
        {--host-port : Bind mail ports directly to the node (default on single-node k3s)}
        {--no-host-port : Skip hostPort — use on managed K8s with a real LoadBalancer}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Stalwart mail server (SMTP/IMAP/JMAP) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployMail();
    }

    protected function deployMail(): int
    {
        $env = $this->resolveEnvironment();
        $host = $this->resolveMailHost($env);

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        // InteractsWithPlex reaches the Commons through its OWN kubectl
        // (plexKubectl()), which is built from $plexContext — not from the
        // $kubectl below. Leaving it null makes every Commons lookup here query
        // whatever context happens to be current (usually local) instead of
        // the environment we're deploying to, so getCommonsSpec() returns null
        // and both configureStalwartStore() and printPlexHint() silently no-op.
        $this->plexContext = $context;

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->assertVpnOnlySupported(ClusterTool::MAIL)) {
            return 1;
        }

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::MAIL, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Stalwart is self-contained (embedded RocksDB store on its PVC) — no Commons.
        // Keep the admin password stable across re-runs by reading it back.
        $adminPassword = $this->readMailSecret($kubectl, $ns, 'admin-password') ?? Str::random(24);

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $adminPassword) {
            Process::run(
                "{$kubectl} create secret generic mail-secrets -n {$ns} "
                .'--from-literal=recovery-admin='.escapeshellarg('admin:'.$adminPassword).' '
                .'--from-literal=admin-password='.escapeshellarg($adminPassword).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $instance = (string) ($this->option('instance') ?: 'main');
        $aliasHosts = $this->resolveToolAliasHosts($kubectl, ClusterTool::MAIL, $instance);

        $manifest = view('k8s.mail.stalwart', [
            'host' => $host,
            'aliasHosts' => $aliasHosts,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'hostPort' => ! $this->option('no-host-port'),
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-mail.yaml';
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Stalwart manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, 'stalwart', 180),
        );
        @unlink($tmp);

        if (! $rolledOut) {
            return 1;
        }

        $this->withSpin('Refreshing Traefik routing...', fn () => $this->restartTraefikIngress($kubectl));

        // Stalwart must attribute abuse/scan/auth-ban tracking to the real
        // client IP via Traefik's X-Forwarded-For, not Traefik's own pod IP —
        // otherwise one bot hit on a scan-bait path permanently bans the
        // reverse proxy itself and takes mail down for every real user
        // behind it. Best-effort like the CORS write below: a miss here is
        // recoverable via mail:check, not a broken deploy.
        $this->stalwartTrustReverseProxy($kubectl, $ns);

        // NOTE: a `dkimManagement.algorithms` write used to sit here, meant to
        // stop Stalwart stamping both Ed25519 and RSA (the SES 554 duplicate
        // DKIM-Signature bounce). It never ran: it used the REST settings
        // endpoint that 0.16 removed, and it was non-fatal, so it failed
        // silently. It was also mistimed — dkimManagement is a field on
        // x:Domain, and no domain exists yet at init.
        // Deliberately NOT reinstated: the duplicate-signature problem is
        // already solved the other way round, by mail:relay calling
        // stalwartEnforceSingleRsaDkimSignature() to keep RSA and prune
        // Ed25519. Reviving this would flip that policy on every cluster.

        // Auto-configure the Postgres main store via Plex Commons + the secrets backend
        // when both are available — allocates the database, pushes the password
        // to the secrets backend as STALWART_STORE_PASSWORD, and creates a sync CRD so
        // Stalwart can read it from an env var instead of manual copy-paste.
        $this->configureStalwartStore($kubectl, $ns);

        // On a cloud VPS, punch the mail L4 ports through both firewall layers
        // (DO cloud edge + host UFW) — klipper binds them, but both default-deny.
        $this->openMailPorts($env);

        $domain = $this->mailDomain($host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Stalwart mail server is live.');
        $this->newLine();
        $this->line("  <fg=gray>Admin console:</>          <fg=blue>https://{$host}/admin</>");
        $this->line('  <fg=gray>Admin login:</>            <fg=blue>admin</> / <fg=blue>'.$adminPassword.'</>');
        $this->line('  <fg=gray>Verify anytime:</>         <fg=blue>larakube mail:check '.$env.'</> <fg=gray>— runs every check below and shows what\'s left.</>');
        $this->newLine();
        $this->line('  <fg=yellow>1. First-run setup</> — open <fg=blue>https://'.$host.'</> and complete the wizard.');
        $this->line('     <fg=gray>(You can configure your stores during the wizard or later in Settings → Storage — see step 7 below).</>');
        $this->line('     <fg=gray>At the wizard\'s "Setup complete" screen, apply it with:</> <fg=blue>larakube mail:restart '.$env.'</>');
        $this->line('     <fg=gray>(the config lives in Stalwart\'s store and needs a restart to load — else /admin loops the wizard).</>');
        $this->line('     <fg=gray>Won\'t load right after (re)deploy? That\'s a stale DNS cache, not a failure —</>');
        $this->line('     <fg=gray>ExternalDNS just (re)created the record. Flush it or use an Incognito window:</>');
        $this->line('       <fg=blue>sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder</> <fg=gray>(macOS)</>');
        $this->newLine();
        $this->line("  <fg=yellow>2. Valid TLS on the mail ports</> (so Apple Mail/Thunderbird don't warn).");
        $this->line('     The web UI cert is handled by the ingress; the mail ports (465/993) need');
        $this->line('     Stalwart to hold its own cert. In <fg=gray>Settings → Server → TLS → ACME Providers</>:');
        $this->line('       • Create provider · Challenge = <fg=blue>DNS-01</>');
        $this->line('       • DNS provider = <fg=blue>Cloudflare</> · paste your API token');
        $this->line("       • Subject names = <fg=blue>{$host}</>");
        $this->newLine();
        $this->line("  <fg=yellow>3. Add the domain</> in <fg=gray>Directory → Domains</> → <fg=blue>{$domain}</>, then open its");
        $this->line('     <fg=gray>DKIM</> tab and copy the generated selector record for step 5.');
        $this->newLine();
        $this->line('  <fg=yellow>4. Create accounts</> in <fg=gray>Directory → Accounts</>:');
        $this->line('       • one per workmate (issues their email address + login)');
        $this->line("       • a <fg=blue>noreply@{$domain}</> account + an <fg=gray>application password</>");
        $this->line('         (used by <fg=gray>larakube mail:wire</>).');
        $this->newLine();
        $this->line("  <fg=yellow>5. DNS records for {$domain}</> (Cloudflare — the A record is auto-created):");
        $this->line("       MX     <fg=gray>{$domain}</>            → <fg=blue>{$host}</>  (priority 10)");
        $this->line('       TXT    <fg=gray>'.$domain.'</>            → <fg=blue>"v=spf1 mx ~all"</>');
        $this->line('       TXT    <fg=gray>(DKIM selector)</>      → paste from step 3');
        $this->line('       TXT    <fg=gray>_dmarc.'.$domain.'</>     → <fg=blue>"v=DMARC1; p=quarantine; rua=mailto:postmaster@'.$domain.'"</>');
        $this->line("       PTR    <fg=gray>rDNS on the droplet</>  → <fg=blue>{$host}</>  (set in your provider)");
        $this->newLine();
        $this->line('  <fg=yellow>6. Sending to external addresses (Gmail, etc.)</> needs an outbound relay —');
        $this->line('     most clouds (incl. DigitalOcean) block outbound port 25, so direct delivery fails.');
        $this->line('     Route outbound through Brevo/SES: <fg=blue>larakube mail:relay</> <fg=gray>(internal + inbound mail work without it).</>');
        $this->newLine();
        $this->line('  <fg=yellow>Apple Mail / Thunderbird (per workmate):</>');
        $this->line("     IMAP:  <fg=blue>{$host}</>  port <fg=blue>993</>  (SSL/TLS)   ·   SMTP:  <fg=blue>{$host}</>  port <fg=blue>465</>  (SSL/TLS)");
        $this->line('     Username: the full email address   ·   Password: the account password');
        $this->newLine();
        $this->line('  <fg=gray>Ports 25/465/587/993/4190 must be reachable.  Wire a tool:</> <fg=blue>larakube mail:wire</>');
        $this->newLine();

        $this->printPlexHint($kubectl, $host);

        $this->registerDeployedTool(ClusterTool::MAIL, $kubectl, $host);

        $this->offerWebmail($env);

        return 0;
    }

    /**
     * Offer to add the Bulwark webmail UI right after mail:init — the discovery
     * hook, mirroring tool:add's offerMailWiring()/offerSsoWiring(). Opt-in and
     * interactive-only: webmail is NOT bundled into mail:init (not every install
     * wants a browser UI, and we don't couple the critical mail deploy to a
     * separate tool's failure modes), this just makes it discoverable.
     */
    protected function offerWebmail(string $env): void
    {
        if ($this->option('no-interaction')) {
            return;
        }

        if (! confirm(label: "Also deploy a browser webmail UI (Bulwark) so your team isn't limited to Apple Mail/Thunderbird?", default: false)) {
            $this->laraKubeLine("  <fg=gray>You can add it later:</> <fg=blue>larakube webmail:init {$env}</>");

            return;
        }

        // webmail:init resolves its own host (local → webmail.{tld}; cloud →
        // prompt/persist) and handles the Stalwart CORS flip + restart itself.
        $this->call('webmail:init', ['environment' => $env]);
    }

    /**
     * Auto-configure Stalwart's Postgres main store via Plex Commons and
     * OpenBao when both are detected on the cluster. Creates the 'stalwart'
     * database and role in the Plex Postgres, pushes the password as
     * STALWART_STORE_PASSWORD to the LaraKube OpenBao project, and creates
     * a CRD that syncs it into a native k8s Secret in
     * the mail namespace. The Deployment already has envFrom with optional:
     * true, so Stalwart can read the env var even if the Secret arrives later.
     */
    protected function configureStalwartStore(string $kubectl, string $ns): void
    {
        // Every bail-out below is a legitimate "not applicable here" case, but
        // silence made them indistinguishable from a bug — the operator saw
        // nothing at all and had no idea whether the step ran, was skipped, or
        // failed. Each now says which precondition was missing and how to meet it.
        $spec = $this->getCommonsSpec();
        if ($spec === null) {
            $this->line('  <fg=gray>Skipped Postgres store auto-config: no Plex Commons on this cluster.</>');
            $this->line('  <fg=gray>  Set one up with</> <fg=blue>larakube plex:init</> <fg=gray>— Stalwart runs on embedded RocksDB until then.</>');

            return;
        }

        $services = $this->enabledCommonsServices($spec);
        if (! in_array('postgres', $services, true)) {
            $this->line('  <fg=gray>Skipped Postgres store auto-config: the Commons has no Postgres service enabled.</>');
            $this->line('  <fg=gray>  Enable it with</> <fg=blue>larakube plex:init</><fg=gray>.</>');

            return;
        }

        if (! $this->secretsBackendAvailable($kubectl)) {
            $this->line('  <fg=gray>Skipped Postgres store auto-config: Secrets backend is not bootstrapped, so there is</>');
            $this->line('  <fg=gray>  nowhere to sync STALWART_STORE_PASSWORD. Run</> <fg=blue>larakube secrets:init</><fg=gray>, or paste the</>');
            $this->line('  <fg=gray>  password from the store details printed below straight into the wizard.</>');

            return;
        }

        $existingPassword = $this->readClusterSecretKey($kubectl, $ns, 'stalwart-openbao', 'STALWART_STORE_PASSWORD');
        $password = $existingPassword ?? Str::random(24);

        // Unlike the checks above, this one is a real failure, not a missing
        // precondition — say so loudly rather than in passing gray.
        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, 'stalwart', $password)) {
            $this->laraKubeError("Could not allocate the 'stalwart' database in the Plex Commons.");
            $this->line('  <fg=gray>Check the Commons Postgres is reachable:</> <fg=blue>larakube plex:show</>');

            return;
        }

        // Auto-allocate S3 bucket 'stalwart' if S3 backend is enabled
        foreach (['seaweedfs', 'minio', 'garage'] as $candidate) {
            if (in_array($candidate, $services, true)) {
                $storageDriver = StorageDriver::tryFrom($candidate);
                if ($storageDriver !== null) {
                    $this->allocateStorageBucket($storageDriver, 'stalwart');
                }
                break;
            }
        }

        // Pushing STALWART store secrets to OpenBao
        $pushed = $this->withSpin(
            'Pushing STALWART store secrets to OpenBao...',
            function () use ($kubectl, $password) {
                if ($this->databaseEngineMounted($kubectl)) {
                    $dbPushed = $this->registerStaticRole($kubectl, 'stalwart');

                    // Without this, STALWART_STORE_PASSWORD is never pushed
                    // to OpenBao's KV at all on this branch — secrets:init's
                    // sweep (tool-es.blade.php) reads it from there, so the
                    // synced Secret would silently end up with no password
                    // key. And even if it HAD been pushed with $password,
                    // registerStaticRole() rotates the real one as a side
                    // effect the instant a role is first created — read back
                    // what OpenBao actually set, not the pre-rotation value.
                    if ($dbPushed) {
                        $realPassword = $this->readStaticRolePassword($kubectl, 'stalwart');
                        if ($realPassword !== null) {
                            $this->pushClusterSecret($kubectl, 'STALWART_STORE_PASSWORD', $realPassword, 'production');
                        }
                    }
                } else {
                    $dbPushed = $this->pushClusterSecret($kubectl, 'STALWART_STORE_PASSWORD', $password, 'production');
                }
                $s3Creds = $this->readCommonsS3Credentials();
                if ($s3Creds !== null) {
                    $this->pushClusterSecret($kubectl, 'STALWART_S3_KEY_ID', $s3Creds['access'], 'production');
                    $this->pushClusterSecret($kubectl, 'STALWART_S3_SECRET_KEY', $s3Creds['secret'], 'production');
                }

                return $dbPushed;
            },
        );

        if (! $pushed) {
            $this->laraKubeError('Could not store STALWART_STORE_PASSWORD in OpenBao.');
            $this->line('  <fg=gray>The database was created, so use this password in the wizard directly:</>');
            $this->line('  <fg=yellow>'.$password.'</>');
            $this->line('  <fg=gray>Username</> <fg=blue>stalwart</> <fg=gray>· database</> <fg=blue>stalwart</>');

            return;
        }

        // NOT syncClusterSecretToNamespace() here — same bug that took down
        // Zitadel (confirmed live 2026-08-02): it extracts KV path
        // "production" as one object, but the value pushed above is at the
        // deeper "production/STALWART_STORE_PASSWORD" path, so it always
        // syncs empty and, as an Owner-mode ExternalSecret with a 1m
        // refresh, wipes out the correct one secrets:init already maintains
        // (tool-es.blade.php) on its next reconcile. Reconcile that existing
        // ExternalSecret instead of creating a second, conflicting one.
        $synced = $this->withSpin(
            'Waiting for stalwart to sync into the cluster...',
            function () use ($kubectl, $ns) {
                $refreshTimeBefore = $this->externalSecretRefreshTime($kubectl, $ns, 'stalwart');
                $this->forceExternalSecretReconcile($kubectl, $ns, 'stalwart');

                return $this->waitForExternalSecretSynced($kubectl, $ns, 'stalwart', $refreshTimeBefore);
            },
        );

        if ($synced) {
            // Restart Stalwart deployment so its container process inherits the freshly synced
            // env vars from the newly created stalwart secret.
            Process::run("{$kubectl} rollout restart deployment/stalwart -n {$ns} >/dev/null 2>&1");
        }

        if (! $synced) {
            $this->laraKubeError('Stored the password in OpenBao, but the sync into the cluster did not confirm in time.');
            $this->line('  <fg=gray>Check</> <fg=yellow>kubectl get externalsecret stalwart -n '.$ns.'</> <fg=gray>— run</> <fg=blue>larakube secrets:init</> <fg=gray>if it is missing. Or use the password directly:</>');
            $this->line('  <fg=yellow>'.$password.'</>');

            return;
        }

        // Wait briefly for the operator to sync the secret
        usleep(3_000_000);

        $this->laraKubeInfo('Stalwart store configured via Plex Commons + OpenBao.');
        $this->laraKubeLine('  Use "Secret read from environment variable" in the wizard and enter "STALWART_STORE_PASSWORD".');
    }

    /**
     * Open Stalwart's L4 ports at both firewall layers on a VPS: the cloud
     * firewall (a dedicated, drift-free firewall) and the host UFW (over SSH).
     * Best-effort — a failure never fails the deploy; it prints the manual fix.
     */
    protected function openMailPorts(string $env): void
    {
        $this->openToolPorts(SharedClusterService::MAIL, $env);
    }

    /** Best-effort mail domain (drops the leftmost "mail." label) for the noreply hint. */
    protected function mailDomain(string $host): string
    {
        $parts = explode('.', $host);

        return count($parts) > 2 ? implode('.', array_slice($parts, 1)) : $host;
    }

    protected function resolveMailHost(string $env, ?string $kubectl = null): string
    {
        return $this->resolveToolHost(SharedClusterService::MAIL, ClusterTool::MAIL, $env, $kubectl);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::MAIL);
    }
}
