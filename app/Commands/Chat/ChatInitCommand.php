<?php

namespace App\Commands\Chat;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesToolFirewallPorts;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolBranding;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\SchedulesCronJobs;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class ChatInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithChat, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithSso, InteractsWithZitadelApi, LaraKubeOutput, ManagesToolFirewallPorts, RequiresFlagsWhenNonInteractive, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, SchedulesCronJobs, StreamsProcessOutput, SyncsClusterSecrets;

    /**
     * Verify against the actual current stable release before shipping —
     * captured 2026-08-21 from https://github.com/element-hq/matrix-authentication-service/releases.
     */
    protected const MAS_IMAGE = 'ghcr.io/element-hq/matrix-authentication-service:1.23.0';

    protected $signature = 'chat:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Chat (example.com → prefix.example.com)}
        {--app-name= : Custom branding name for the Element Web UI (defaults to Chat)}
        {--logo-url= : Custom logo URL for the Element Web UI}
        {--no-plex   : Bypass Plex Commons and bundle dedicated storage}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--no-host-port : Skip hostPort on Coturn — use on managed K8s with a real LoadBalancer}
        {--media-retention=30d : Keep media local for this long after last access; older files live only in S3 (s, h, d, m, y)}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Team Chat stack (Matrix / Synapse) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployChat();
    }

    protected function deployChat(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->chatKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::CHAT, ClusterTool::CHAT, $env, $kubectl);
        $ns = $this->chatNamespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::CHAT, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres', 'seaweedfs'])) {
                return 1;
            }
        }

        $dbPassword = $this->readChatSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $registrationSecret = $this->readChatSecret($kubectl, $ns, 'registration-secret') ?? Str::random(32);
        $turnSecret = $this->readChatSecret($kubectl, $ns, 'turn-secret') ?? Str::random(32);

        $dbName = 'chat_matrix';
        $dbUser = 'chat_matrix';

        $s3AccessKey = '';
        $s3SecretKey = '';

        if (! $noPlex) {
            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
                return 1;
            }
            $this->allocateStorageBucket(StorageDriver::SEAWEEDFS, 'chat-media');

            $creds = $this->readCommonsS3Credentials();
            if ($creds === null) {
                $this->laraKubeError('Commons S3 credentials not found. Re-run `larakube plex:init`.');

                return 1;
            }

            $s3AccessKey = $creds['access'];
            $s3SecretKey = $creds['secret'];
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $registrationSecret, $turnSecret): void {
            Process::run(
                "{$kubectl} create secret generic chat-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=registration-secret='.escapeshellarg($registrationSecret).' '
                .'--from-literal=turn-secret='.escapeshellarg($turnSecret).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        // homeserver.yaml moved from ConfigMap to Secret
        $this->withSpin('Migrating config storage (ConfigMap → Secret)...', fn () => Process::run(
            "{$kubectl} delete configmap chat-synapse-config -n {$ns} --ignore-not-found",
        ));

        // Re-hydrate any wired mail / SSO values so a re-run does not erase them.
        $smtp = $this->readChatWiredSmtp($kubectl, $ns);
        $oidc = $this->readChatWiredOidc($kubectl, $ns);
        // MAS-delegated auth is active only when classic oidc_providers:
        // ISN'T — this is the whole safety invariant for the one already-
        // live install this repo has (chat.luchtech.dev) that still has
        // chat-oidc from before MAS existed: as long as that Secret is
        // present, this stays null and Synapse keeps rendering
        // oidc_providers:, no matter what MAS's own deploy state is. A
        // fresh install never has chat-oidc to begin with, so it reads MAS
        // as active the moment deployMas() below has run once. Migrating
        // that one existing install off classic OIDC is a manual, one-time
        // action (see the chat-mas plan) — not shipped CLI code, since it
        // will never be needed again once it's done.
        $mas = $oidc === null ? $this->readChatWiredMas($kubectl, $ns) : null;
        // Calling lives in the Meet tool now — chat only records that it is
        // wired, so a re-run cannot silently disable it.
        $meetJwtUrl = $this->readChatWiredMeet($kubectl, $ns);
        $branding = $this->resolveToolBranding($kubectl, ClusterTool::CHAT);

        $manifest = view('k8s.chat.matrix', [
            'host' => $host,
            'appName' => $branding['appName'],
            'logoUrl' => $branding['logoUrl'],
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            's3Endpoint' => $noPlex ? '' : "http://seaweedfs.{$this->plexNamespace()}.svc.cluster.local:8333",
            's3Bucket' => $noPlex ? '' : 'chat-media',
            's3AccessKey' => $s3AccessKey,
            's3SecretKey' => $s3SecretKey,
            'dbName' => $dbName,
            'dbUser' => $dbUser,
            'dbPassword' => $dbPassword,
            'registrationSecret' => $registrationSecret,
            'turnSecret' => $turnSecret,
            'meetJwtUrl' => $meetJwtUrl,
            'mediaRetention' => (string) $this->option('media-retention') ?: '30d',
            // Without this the prune's 02:41 is read as UTC — 10:41 in Manila,
            // squarely in business hours. Same trap the backup CronJob hit.
            'mediaPruneTimezone' => $this->detectTimezone(),
            'hostPort' => ! $this->option('no-host-port'),
            'externalIp' => $this->toolFirewallCloud($env)?->ip ?? gethostbyname($host),
            'smtp' => $smtp,
            'oidc' => $oidc,
            'mas' => $mas,
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-chat.yaml');
        file_put_contents($tmp, $manifest);

        $engineLabel = 'Matrix (Synapse + Element)';
        $this->withSpin("Applying {$engineLabel} manifests...", fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        $temporaryDirectory->delete();

        $this->withSpin("Waiting for {$engineLabel}...", fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/chat-synapse -n {$ns} --timeout=180s",
        ));

        $this->registerDeployedTool(ClusterTool::CHAT, $kubectl, $host);

        // Matrix Authentication Service — deployed unconditionally, same tier
        // as Coturn/the web client above, whenever Zitadel is already available.
        // Element X (the official mobile client) only supports MSC3861/
        // MAS-native OIDC login, not the classic oidc_providers: block
        // above, so this is what "things needed for chat" means for anyone
        // who wants the mobile app. When Zitadel isn't installed yet, this
        // is skipped with the same informational note SMTP/OIDC already use
        // — MAS with no upstream IdP configured would just sit idle.
        // resolveSsoHostReadOnly() reads the LIVE cluster-registered host
        // first when $kubectl is given, only falling back to local project
        // config — passing null here (chat:init loads no ConfigData of its
        // own) is safe rather than a missing-context bug.
        $ssoHost = $this->resolveSsoHostReadOnly($env, null, $kubectl);
        $masDeployed = false;
        if ($ssoHost !== null && $this->isSsoInstalled($kubectl, $this->ssoNamespace())) {
            $masDeployed = $this->deployMas($kubectl, $ns, $host, $ssoHost, $env, $noPlex);

            // Fresh install (or one already off classic OIDC): MAS just
            // became available for the FIRST time this run and nothing else
            // occupies the auth slot, so activate it immediately — a fresh
            // cluster reaches full MAS-native auth in ONE chat:init run,
            // never needing a separate migration concept at all. Re-runs
            // where MAS was already active skip this (steady state, no
            // pointless restart); an existing install still on classic OIDC
            // never reaches this branch at all ($oidc !== null forced $mas
            // to null above, unconditionally, regardless of MAS's own state).
            if ($masDeployed && $oidc === null && $mas === null) {
                $this->activateMasAuthMode($kubectl, $ns);
                $mas = $this->readChatWiredMas($kubectl, $ns);
            }
        }

        // Element Admin — users/rooms management console. Needs MAS's own
        // Admin API to be meaningful (it's a static SPA that logs the
        // operator in via MAS-native OIDC, then exercises Synapse's/MAS's
        // Admin APIs using that session), so gated on $mas being the ACTIVE
        // auth mode, not just deployed — deploying it before that would be
        // a UI with nothing to authenticate against. Same "unconditional
        // once its prerequisite exists" tier as MAS itself above.
        $adminDeployed = false;
        if ($mas !== null) {
            $adminDeployed = $this->deployAdmin($kubectl, $ns, $host, $env);
        }

        // On a cloud VPS, punch Coturn's raw UDP/TCP ports through both
        // firewall layers (DO cloud edge + host UFW) — klipper binds them via
        // hostPort, but both default-deny, so TURN silently never connects.
        // The SFU's own ports belong to `meet:init`.
        $this->openToolPorts(SharedClusterService::CHAT, $env);

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ {$engineLabel} is live.");
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->newLine();
        $this->line('  <fg=gray>Interface:</>   Element Web Client');
        $this->line('  <fg=gray>Homeserver:</>  Synapse (connected to PostgreSQL database chat_matrix)');
        $this->newLine();

        if ($smtp !== null) {
            $this->line('  <fg=green>✓</> <fg=gray>Outbound email:</> <fg=blue>'.($smtp['from'] ?: 'wired').'</>');
        } else {
            $this->line('  <fg=gray>Outbound notification email:</> <fg=blue>larakube mail:wire chat</>');
        }

        if ($mas !== null) {
            $this->line('  <fg=green>✓</> <fg=gray>SSO provider:</>   <fg=blue>Zitadel (via Matrix Authentication Service)</>');
        } elseif ($oidc !== null) {
            $this->line('  <fg=green>✓</> <fg=gray>SSO provider:</>   <fg=blue>'.$oidc['name'].'</>');
        } else {
            $this->line('  <fg=gray>Identity Provider SSO:</>        <fg=blue>larakube sso:wire chat</>');
        }

        if ($mas !== null) {
            $this->line('  <fg=green>✓</> <fg=gray>Element X (mobile):</> <fg=blue>ready</>');
        } elseif ($masDeployed) {
            // Only reachable while chat-oidc still exists — the one
            // already-live install predating MAS. Not a state a fresh
            // install ever passes through.
            $this->line('  <fg=gray>Element X (mobile):</> <fg=blue>MAS deployed, pending manual migration off classic SSO</>');
        } else {
            $this->line('  <fg=gray>Element X (mobile):</> <fg=blue>needs Zitadel — run `larakube sso:init` first</>');
        }

        if ($adminDeployed) {
            $this->line('  <fg=green>✓</> <fg=gray>Admin console:</>  <fg=blue>https://admin.'.$host.'</> <fg=gray>(VPN-only, grant yourself Synapse admin via `chat:user --admin`)</>');
        }

        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::CHAT);
    }

    /**
     * Deploy/update Matrix Authentication Service and register it as its OWN,
     * independent Zitadel OIDC client — NOT folded into sso:wire's
     * single-schema-per-tool dispatcher (its redirect path is structurally
     * incompatible with Synapse's own oidc_providers: callback, and MAS is a
     * completely separate auth consumer from Synapse itself).
     *
     * Idempotent and safe on every chat:init re-run: it never touches
     * Synapse's own auth mode itself — the caller (deployChat()) decides
     * whether to activate it, via activateMasAuthMode() below, based on
     * whether classic OIDC is already occupying that slot. Returns whether
     * MAS ended up deployed, not whether it's the active auth mode.
     */
    protected function deployMas(string $kubectl, string $ns, string $host, string $ssoHost, string $env, bool $noPlex): bool
    {
        $masHost = "mas.{$host}";

        // 1. MAS's own Postgres tenant.
        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres'])) {
                return false;
            }
        }

        $masDbPassword = $this->readClusterSecretKey($kubectl, $ns, 'chat-mas-secrets', 'db-password') ?? Str::random(24);
        $masDbHost = $noPlex ? 'chat-mas-db' : "postgres.{$this->plexNamespace()}.svc.cluster.local";
        $masDbName = 'chat_mas';

        if (! $noPlex) {
            $masDbPassword = $this->resolveManagedDbPassword($kubectl, 'chat_mas', $masDbPassword);
            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, 'chat_mas', $masDbPassword)) {
                return false;
            }
        }
        // The bundled --no-plex Postgres (chat-mas-db) is rendered as part
        // of the k8s.chat.mas view below, same posture as chat-synapse-db
        // above — applied together with everything else in one
        // kubectl apply -f, not a separate ad-hoc manifest.

        // 2. Synapse↔MAS internal trust secret — plain random string, NOT an
        //    OIDC value. Read back so re-runs don't rotate it (that would
        //    require restarting Synapse too, which this method never does).
        $masTrustSecret = $this->readClusterSecretKey($kubectl, $ns, 'chat-mas-secrets', 'trust-secret') ?? Str::random(32);

        Process::run(
            "{$kubectl} create secret generic chat-mas-secrets -n {$ns} "
            .'--from-literal=db-password='.escapeshellarg($masDbPassword).' '
            .'--from-literal=trust-secret='.escapeshellarg($masTrustSecret).' '
            // Resolved once, here, at deploy time — readChatWiredMas() just
            // reads it back rather than re-deriving it from a host argument
            // at every call site (several of which don't have chat's own
            // host readily at hand).
            .'--from-literal=public-issuer='.escapeshellarg("https://{$masHost}/").' '
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        );

        // 3. Register MAS itself as an independent Zitadel OIDC client.
        $pat = $this->readSsoSecret($kubectl, $this->ssoNamespace(), 'machine-pat');
        if ($pat === null) {
            $this->laraKubeLine('  <fg=gray>Skipping Matrix Authentication Service — could not reach Zitadel\'s automation credentials (re-run `larakube sso:init` to recapture them).</>');

            return false;
        }

        $providerId = $this->readClusterSecretKey($kubectl, $this->ssoNamespace(), 'sso-app-chat-mas', 'provider-id') ?? (string) Str::uuid();
        $redirectUri = "https://{$masHost}/upstream/callback/{$providerId}";

        $registered = null;
        $this->withSpin('Registering Matrix Authentication Service as an OIDC client in Zitadel...', function () use (&$registered, $ssoHost, $pat, $redirectUri): void {
            $projectId = $this->zitadelEnsureProject($ssoHost, $pat, 'LaraKube Shared Tools');
            if ($projectId === null) {
                return;
            }

            $app = $this->zitadelCreateOidcApp($ssoHost, $pat, $projectId, 'Matrix (MAS)', $redirectUri);
            if ($app === null) {
                return;
            }

            $registered = array_merge($app, ['projectId' => $projectId]);
        });

        if ($registered === null) {
            $this->laraKubeLine('  <fg=gray>Could not register Matrix Authentication Service in Zitadel — check the automation credentials and Zitadel\'s own logs.</>');

            return false;
        }

        Process::run(
            "{$kubectl} create secret generic sso-app-chat-mas -n {$this->ssoNamespace()} "
            .'--from-literal=project-id='.escapeshellarg($registered['projectId']).' '
            .'--from-literal=app-id='.escapeshellarg($registered['appId']).' '
            .'--from-literal=client-id='.escapeshellarg($registered['clientId']).' '
            .'--from-literal=client-secret='.escapeshellarg($registered['clientSecret']).' '
            .'--from-literal=provider-id='.escapeshellarg($providerId).' '
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        );

        // 4. Bootstrap or re-patch chat-mas-config. First run: generate a
        //    real base config (with real crypto material) via a throwaway
        //    pod running the actual MAS image — never fabricate
        //    encryption/signing keys here. Re-runs: patch the EXISTING
        //    config, which naturally preserves its `secrets:` block since
        //    renderMasConfig() only ever touches database/matrix/upstream_oauth2.
        $existingConfig = trim(Process::run(
            "{$kubectl} get secret chat-mas-config -n {$ns} -o jsonpath='{.data.config\.yaml}'",
        )->output());

        if ($existingConfig !== '') {
            $baseYaml = (string) base64_decode($existingConfig);
        } else {
            $generated = null;
            $this->withSpin('Generating Matrix Authentication Service config (real crypto keys via mas-cli)...', function () use (&$generated, $kubectl, $ns): void {
                $podName = 'chat-mas-config-gen-'.Str::lower(Str::random(6));
                $result = Process::timeout(60)->run(
                    "{$kubectl} run {$podName} -n {$ns} --restart=Never --rm -i --image=".self::MAS_IMAGE.' --command -- mas-cli config generate',
                );
                $generated = $result->successful() ? $result->output() : null;
            });

            if ($generated === null || trim($generated) === '') {
                $this->laraKubeLine('  <fg=gray>Could not generate a base Matrix Authentication Service config — check that the cluster can pull '.self::MAS_IMAGE.'.</>');

                return false;
            }

            $baseYaml = $generated;
        }

        $configYaml = $this->renderMasConfig(
            $baseYaml,
            ['host' => $masDbHost, 'user' => 'chat_mas', 'password' => $masDbPassword, 'database' => $masDbName],
            ['homeserver' => $host, 'secret' => $masTrustSecret],
            ['id' => $providerId, 'issuer' => "https://{$ssoHost}", 'client_id' => $registered['clientId'], 'client_secret' => $registered['clientSecret']],
        );

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmp = $temporaryDirectory->path().'/config.yaml';
        file_put_contents($tmp, $configYaml);
        Process::run(
            "{$kubectl} create secret generic chat-mas-config -n {$ns} --from-file=config.yaml={$tmp} --dry-run=client -o yaml | {$kubectl} apply -f -",
        );
        $temporaryDirectory->delete();

        // 5. Apply the chat-mas Deployment/Service/Ingress.
        $manifest = view('k8s.chat.mas', [
            'masImage' => self::MAS_IMAGE,
            'masConfigHash' => substr(hash('sha256', $configYaml), 0, 16),
            'masHost' => $masHost,
            'isLocal' => $env === 'local',
            'proxied' => false,
            'noPlex' => $noPlex,
        ])->render();

        $manifestTemporaryDirectory = TemporaryDirectory::make();
        $manifestTmp = $manifestTemporaryDirectory->path('larakube-chat-mas.yaml');
        file_put_contents($manifestTmp, $manifest);
        $this->withSpin('Applying Matrix Authentication Service manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$manifestTmp}"));
        $manifestTemporaryDirectory->delete();

        $this->withSpin('Waiting for Matrix Authentication Service...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/chat-mas -n {$ns} --timeout=120s",
        ));

        return true;
    }

    /**
     * Live-patch Synapse's homeserver.yaml to matrix_authentication_service:
     * mode and restart it — the ONLY caller is deployChat() above, and only
     * when it has already established that classic OIDC isn't occupying
     * that slot (fresh install, or one already migrated off it by hand). No
     * separate "cutover" command exists for this: it's always safe to call,
     * because whenever it's unsafe (classic OIDC still active with real
     * users), the caller never reaches this method in the first place.
     */
    protected function activateMasAuthMode(string $kubectl, string $ns): void
    {
        $mas = $this->readChatWiredMas($kubectl, $ns);
        if ($mas === null) {
            return;
        }

        $smtp = $this->readChatWiredSmtp($kubectl, $ns);
        $raw = trim(Process::run("{$kubectl} get secret chat-synapse-config -n {$ns} -o jsonpath='{.data.homeserver\.yaml}'")->output());
        if ($raw === '') {
            return;
        }

        $homeserver = $this->renderSynapseConfig((string) base64_decode($raw), $smtp, null, $mas);

        $meetJwtUrl = $this->readChatWiredMeet($kubectl, $ns);
        $homeserver = $this->renderSynapseCalling($homeserver, $meetJwtUrl, $mas['public_issuer']);

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmp = $temporaryDirectory->path().'/homeserver.yaml';
        file_put_contents($tmp, $homeserver);
        $applied = Process::run(
            "{$kubectl} create secret generic chat-synapse-config -n {$ns} --from-file=homeserver.yaml={$tmp} --dry-run=client -o yaml | {$kubectl} apply -f -",
        )->successful();
        $temporaryDirectory->delete();

        if ($applied) {
            $this->withSpin('Activating Matrix Authentication Service auth...', fn () => $this->runStreaming(
                "{$kubectl} rollout restart deployment/chat-synapse -n {$ns}",
            ));
        }
    }

    /**
     * Deploy Element Admin — a static SPA with no credentials of its own to
     * manage. It logs the operator in via MAS-native OIDC (same flow as any
     * other Matrix client, discovered from SERVER_NAME) and exercises
     * Synapse's/MAS's own Admin APIs using that session, scoped to whatever
     * admin privileges the account already holds. Its Ingress is VPN-only
     * unconditionally (see admin.blade.php's own comment on why), so this
     * must ensure the shared VPN Middleware exists even on installs that
     * never passed chat:init --vpn-only — a Traefik router referencing a
     * missing Middleware 500s every request, not a harmless no-op.
     */
    protected function deployAdmin(string $kubectl, string $ns, string $host, string $env): bool
    {
        if (! $this->ensureVpnMiddleware(ClusterTool::CHAT, $kubectl)) {
            $this->laraKubeLine('  <fg=gray>Skipping Element Admin — could not create its required VPN-only Middleware.</>');

            return false;
        }

        $manifest = view('k8s.chat.admin', [
            'host' => $host,
            'isLocal' => $env === 'local',
            'proxied' => false,
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-chat-admin.yaml');
        file_put_contents($tmp, $manifest);
        $applied = $this->withSpin('Applying Element Admin manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}") === 0);
        $temporaryDirectory->delete();

        if (! $applied) {
            return false;
        }

        $this->withSpin('Waiting for Element Admin...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/chat-admin -n {$ns} --timeout=60s",
        ));

        return true;
    }
}
