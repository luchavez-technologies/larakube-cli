<?php

namespace App\Commands\Git;

use App\Enums\ClusterTool;
use App\Enums\CommonsSecret;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithGitForge;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesToolFirewallPorts;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class GitInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithGitForge, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, ManagesToolFirewallPorts, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets;

    /**
     * Labels the Actions runner advertises, server-side. Kept in sync by hand
     * with the `runner.labels` block in k8s/git/forgejo.blade.php, which maps
     * each to its container image — a label present on one side only means jobs
     * either never dispatch or dispatch to a runner that cannot execute them.
     *
     * @var list<string>
     */
    protected const RUNNER_LABELS = ['ubuntu-latest', 'ubuntu-22.04', 'docker'];

    protected $signature = 'git:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the Forgejo host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for Forgejo (example.com → git.example.com; git.example.com used as-is)}
        {--admin-email= : Email for the Forgejo admin account (defaults to admin@<your domain>)}
        {--no-plex   : Bypass Plex Commons and use local PVC storage instead}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the cluster-wide Forgejo forge, CI/CD runner, and package registry';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployGit();
    }

    protected function deployGit(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->gitKubectl($context);
        $ns = $this->gitNamespace();
        $noPlex = (bool) $this->option('no-plex');

        $s3Service = null;
        $s3Endpoint = '';
        $s3AccessKey = '';
        $s3SecretKey = '';

        if (! $noPlex) {
            $spec = $this->getCommonsSpec();
            if ($spec !== null) {
                $enabled = $this->enabledCommonsServices($spec);
                if (in_array('seaweedfs', $enabled, true)) {
                    $s3Service = 'seaweedfs';
                } elseif (in_array('minio', $enabled, true)) {
                    $s3Service = 'minio';
                }
            }

            if ($s3Service === null) {
                // Default to SeaweedFS (Apache-licensed)
                if (! $this->ensureCommons(['seaweedfs'])) {
                    return 1;
                }
                $s3Service = 'seaweedfs';
            } else {
                if (! $this->ensureCommons([$s3Service])) {
                    return 1;
                }
            }

            // Read credentials and endpoints
            $creds = $this->readCommonsS3Credentials();
            if ($creds === null) {
                $this->laraKubeError('Commons S3 credentials not found. Re-run `larakube plex:init`.');

                return 1;
            }

            $s3AccessKey = $creds['access'];
            $s3SecretKey = $creds['secret'];
            $driver = StorageDriver::from($s3Service);
            $s3Endpoint = "http://{$s3Service}.{$this->plexNamespace()}.svc.cluster.local:{$driver->port()}";

            // Allocate S3 buckets
            if (! $this->allocateStorageBucket($driver, 'forgejo-storage') ||
                ! $this->allocateStorageBucket($driver, 'forgejo-packages') ||
                ! $this->allocateStorageBucket($driver, 'forgejo-lfs')) {
                return 1;
            }
        }

        $host = $this->resolveToolHost(SharedClusterService::GITEA, ClusterTool::GIT, $env, $kubectl);

        // Read or generate password & secrets
        $adminPassword = $this->readExistingAdminPassword($kubectl, $ns);
        if ($adminPassword === null) {
            $adminPassword = Str::random(16);
        }

        $adminEmail = $this->readForgejoSecret($kubectl, $ns, 'admin-email')
            ?? $this->resolveAdminEmail($host);

        // Was reading the `password` key — i.e. the ADMIN password — so a re-run
        // silently reset the database role to the admin's password.
        $dbPassword = $this->readForgejoSecret($kubectl, $ns, 'db-password') ?? Str::random(24);

        $redisIndex = null;

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres', 'redis'])) {
                return 1;
            }
            if (! $this->allocateDatabase(\App\Enums\DatabaseDriver::POSTGRESQL, 'forgejo', $dbPassword)) {
                return 1;
            }

            // Without this Gitea keeps sessions in files on its PVC and the cache
            // in memory, so every pod restart signs everyone out.
            $redisIndex = $this->allocateCommonsRedisIndex('forgejo');
            if ($redisIndex === null) {
                $this->laraKubeError('The Commons Valkey has no free logical DB index (all 16 in use).');

                return 1;
            }
        }

        // 40 hex chars: first 16 are the runner identifier, last 24 the secret.
        // Read-or-generate: a fresh secret on every re-run would orphan the
        // previously registered runner.
        $runnerSecret = $this->readForgejoSecret($kubectl, $ns, 'runner-secret') ?? bin2hex(random_bytes(20));
        $oauthJwtSecret = $this->readForgejoSecret($kubectl, $ns, 'oauth-jwt-secret')
            ?? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // MUST be read before the first manifest apply below, not after: that
        // apply rewrites the Secret, so reading later would only ever see the
        // placeholder it just wrote. An access token's value is shown once, at
        // creation, and Forgejo has no `delete-access-token` — so this stored
        // copy is the ONLY copy, and losing it means the name is burned forever.
        $registryToken = $this->readForgejoSecret($kubectl, $ns, 'registry-token');
        if ($registryToken === 'pending' || $registryToken === '') {
            $registryToken = null;
        }

        // Read-or-generate, like every other credential above. These used to be
        // regenerated unconditionally, which rolled the pod on every re-run for
        // no reason — and worse, SECRET_KEY is what Forgejo encrypts stored data
        // with (2FA enrollments among it), so rotating it silently made that data
        // unreadable.
        $secretKey = $this->readForgejoSecret($kubectl, $ns, 'secret-key') ?? Str::random(16);
        $internalToken = $this->readForgejoSecret($kubectl, $ns, 'internal-token') ?? Str::random(16);
        // base64url, no padding: decodes to exactly the 32 bytes Forgejo wants.
        $jwtSecret = $this->readForgejoSecret($kubectl, $ns, 'lfs-jwt-secret')
            ?? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // Ensure namespace exists
        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // After the namespace exists — the sync applies CRDs INTO it, so calling
        // this next to allocateDatabase() would fail on a fresh cluster.
        // --no-plex has no Commons tenant to sync, hence the false default.
        $secretsSynced = false;
        if (! $noPlex) {
            $secretsSynced = $this->syncDbPasswordToCluster($kubectl, $ns, $env, $dbPassword);
        }

        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::GIT, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // 1. Initial deployment with Gitea Core only (runner token placeholder)
        $manifest = view('k8s.git.forgejo', [
            'host' => $host,
            'adminPassword' => $adminPassword,
            'adminEmail' => $adminEmail,
            'dbPassword' => $dbPassword,
            // Carry the existing token through the first pass — 'pending' here
            // would overwrite it before the read-back above could be used.
            'registryToken' => $registryToken ?? 'pending',
            'runnerSecret' => 'pending',
            'oauthJwtSecret' => $oauthJwtSecret,
            'secretKey' => $secretKey,
            'internalToken' => $internalToken,
            'jwtSecret' => $jwtSecret,
            'noPlex' => $noPlex,
            'redisIndex' => $redisIndex,
            's3Endpoint' => $s3Endpoint,
            's3AccessKey' => $s3AccessKey,
            's3SecretKey' => $s3SecretKey,
            'plexNamespace' => $this->plexNamespace(),
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'vpnOnly' => $vpnOnly,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-forgejo.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Forgejo core manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Forgejo rollout...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/forgejo -n {$ns} --timeout=120s",
            130,
        ));

        // 2. CLI commands inside pod to create user and get tokens.

        $this->withSpin('Initializing Forgejo admin user...', function () use ($kubectl, $ns, $adminPassword, $adminEmail) {
            $list = Process::run("{$kubectl} exec deploy/forgejo -n {$ns} -- su-exec git forgejo --config /data/gitea/conf/app.ini admin user list")->output();
            if (str_contains($list, 'larakube')) {
                return true;
            }

            return Process::run(
                "{$kubectl} exec deploy/forgejo -n {$ns} -- ".
                'su-exec git forgejo --config /data/gitea/conf/app.ini admin user create '.
                '--username larakube --password '.escapeshellarg($adminPassword).' '.
                '--email '.escapeshellarg($adminEmail).' --admin',
            )->successful();
        });

        if ($registryToken === null) {
            $this->withSpin('Generating Forgejo package registry token...', function () use ($kubectl, $ns, &$registryToken) {
                // Unique name per issue. Forgejo rejects a duplicate token name
                // ("access token name has been used already") and offers no way
                // to delete one from the CLI, so a fixed name would permanently
                // wedge this step the moment a token is lost.
                $name = 'larakube-registry-'.Str::lower(Str::random(6));

                $result = Process::run(
                    "{$kubectl} exec deploy/forgejo -n {$ns} -- ".
                    'su-exec git forgejo --config /data/gitea/conf/app.ini admin user generate-access-token '.
                    '--username larakube --token-name '.escapeshellarg($name).' '.
                    '--scopes write:package,read:package --raw',
                );

                if (! $result->successful()) {
                    return false;
                }

                $registryToken = trim($result->output());

                return true;
            });

            if ($registryToken === null) {
                $this->laraKubeError(
                    'Could not mint the package registry token — Forgejo is up, but pushing packages '
                    ."to it will fail. Re-run `larakube git:init {$env}` once the pod is healthy.",
                );
            }
        }

        // Every forgejo command runs through `su-exec git`: kubectl exec lands
        // as root and Forgejo hard-refuses to run as root ("Forgejo is not
        // supposed to be run as root. Sorry."). The entrypoint normally drops
        // privileges for us; an exec bypasses it.
        //
        // Forgejo has no `generate-runner-token`. It uses OFFLINE registration:
        // we mint a 40-char hex secret (first 16 chars become the runner UUID)
        // and tell the server about it. The runner then self-registers with the
        // same secret, so there is nothing to read back off the server.
        $registered = false;
        $this->withSpin('Registering the Forgejo Actions runner...', function () use ($kubectl, $ns, $runnerSecret, &$registered) {
            $registered = Process::run(
                "{$kubectl} exec deploy/forgejo -n {$ns} -- ".
                'su-exec git forgejo forgejo-cli actions register '.
                '--name larakube --secret '.escapeshellarg($runnerSecret).' '.
                // MUST be passed. `register` is idempotent but NOT label-preserving:
                // called without --labels it rewrites the existing runner's
                // agent_labels to empty. The daemon only declares its labels when
                // it starts, so a re-run of git:init against an already-running
                // runner silently strips them — every job then queues forever on
                // "Waiting for a runner with the following label: ubuntu-latest"
                // while the runner sits there, online and idle.
                // Names only here; the config.yml side carries the :docker://image
                // mapping, and these must stay in sync with it.
                '--labels '.escapeshellarg(implode(',', self::RUNNER_LABELS)),
            )->successful();

            return $registered;
        });

        // 3. Re-apply final configuration containing real tokens
        $manifestFinal = view('k8s.git.forgejo', [
            'host' => $host,
            'adminPassword' => $adminPassword,
            'adminEmail' => $adminEmail,
            'dbPassword' => $dbPassword,
            'registryToken' => $registryToken ?? 'pending',
            'runnerSecret' => $runnerSecret,
            'oauthJwtSecret' => $oauthJwtSecret,
            'secretKey' => $secretKey,
            'internalToken' => $internalToken,
            'jwtSecret' => $jwtSecret,
            'noPlex' => $noPlex,
            'redisIndex' => $redisIndex,
            's3Endpoint' => $s3Endpoint,
            's3AccessKey' => $s3AccessKey,
            's3SecretKey' => $s3SecretKey,
            'plexNamespace' => $this->plexNamespace(),
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            'vpnOnly' => $vpnOnly,
        ])->render();

        $tmpFinal = sys_get_temp_dir().'/larakube-forgejo-final.yaml';
        file_put_contents($tmpFinal, $manifestFinal);

        $this->withSpin('Applying Forgejo Actions runner...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmpFinal}"));
        @unlink($tmpFinal);

        if ($registered) {
            $this->withSpin('Waiting for Actions Runner...', fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/forgejo-runner -n {$ns} --timeout=120s",
                130,
            ));
        }

        // The forgejo-ssh Service is a LoadBalancer on 2222, but both the cloud
        // firewall and the host UFW default-deny it — without this, `git clone
        // ssh://…` hangs against a Service that looks perfectly healthy.
        $this->openToolPorts(SharedClusterService::GITEA, $env);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Forgejo forge and Actions runner are live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Admin Email:</>             {$adminEmail}");
        $this->line('  <fg=gray>Admin Username:</>          larakube');
        $this->line("  <fg=gray>Admin Password:</>          {$adminPassword}");
        $this->line("  <fg=gray>SSH clone:</>               ssh://git@{$host}:2222/<owner>/<repo>.git");
        $this->newLine();

        return 0;
    }

    /**
     * The admin's email. Never invent one: it is the address password resets and
     * notifications go to, and SSO links accounts BY EMAIL — a fake
     * `@larakube.local` admin can never be matched by a Zitadel identity, so
     * signing in via SSO would silently create a second, non-admin account.
     */
    protected function resolveAdminEmail(string $host): string
    {
        $explicit = trim((string) ($this->option('admin-email') ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $parts = explode('.', $host);
        $default = 'admin@'.(count($parts) >= 2 ? implode('.', array_slice($parts, 1)) : $host);

        if ($this->cannotPrompt()) {
            return $default;
        }

        return (string) text(
            label: 'Admin email for the Forgejo account',
            default: $default,
            required: true,
        );
    }

    /** Read any key from the forgejo-admin secret; null when absent. */
    /**
     * Mirror the Plex Commons tenant password into OpenBao.
     *
     * Deliberately ONLY the Commons credential — the same line mail draws. A
     * Commons tenant password is a SHARED value: `plex:rotate` changes it on the
     * Postgres side, and without an OpenBao copy there is nothing for a
     * rotation to update, so Forgejo would keep booting with a stale literal.
     *
     * Forgejo's own identity (secret-key, registry-token, oauth-jwt-secret,
     * runner-secret) stays k8s-only on purpose: a forge is foundational enough
     * that its own credentials must not depend on another service being
     * reachable — the same reasoning that keeps Stalwart's api-key and recovery
     * admin out of OpenBao.
     *
     * Best-effort throughout. OpenBao is an optional capability, so a cluster
     * without it falls through silently; a cluster WITH it that fails reports
     * why, because a half-synced rotation source is worse than none.
     */
    protected function syncDbPasswordToCluster(string $kubectl, string $ns, string $env, string $dbPassword): bool
    {
        if (! $this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
            return false;
        }

        // Environment slugs: 'dev' is the built-in local one,
        // every other environment is user-named and passed through as-is.
        $clusterEnv = $env === 'local' ? 'dev' : $env;

        // Ask the enum for the key rather than spelling it out. plex:rotate
        // writes the rotated value to CommonsSecret::TENANT_DB->clusterSecretKey(),
        // so a hand-written name here would rotate into a key nothing reads —
        // the exact failure this sync exists to prevent.
        $key = CommonsSecret::TENANT_DB->clusterSecretKey('forgejo');
        if ($key === null) {
            return false;
        }

        $ok = $this->withSpin(
            "Syncing {$key} to the cluster...",
            function () use ($kubectl, $ns, $clusterEnv, $dbPassword, $key) {
                if ($this->databaseEngineMounted($kubectl)) {
                    $synced = $this->registerStaticRole($kubectl, 'forgejo', 'plex-postgres', 'forgejo');

                    // Without this, $key is never pushed to OpenBao's KV at
                    // all on this branch — secrets:init's sweep reads it
                    // from there, so the synced Secret would end up with no
                    // password key. And even if it had been pushed with
                    // $dbPassword, registerStaticRole() rotates the real one
                    // as a side effect the instant a role is first created —
                    // read back what OpenBao actually set, not the
                    // pre-rotation value. Same class of bug that desynced
                    // Zitadel, confirmed live 2026-08-02.
                    if ($synced) {
                        $realPassword = $this->readStaticRolePassword($kubectl, 'forgejo');
                        if ($realPassword !== null) {
                            $this->pushClusterSecret($kubectl, $key, $realPassword, $clusterEnv);
                        }
                    }
                } else {
                    $synced = $this->pushClusterSecret($kubectl, $key, $dbPassword, $clusterEnv);
                }

                if (! $synced) {
                    return false;
                }

                // NOT syncClusterSecretToNamespace() here — same bug that
                // took down Zitadel (confirmed live 2026-08-02): it extracts
                // KV path "{env}" as one object, but $key above is written
                // at the deeper "{env}/{$key}" path, so it always syncs
                // empty and, as an Owner-mode ExternalSecret with a 1m
                // refresh, wipes out the correct one secrets:init already
                // maintains (tool-es.blade.php) on its next reconcile —
                // Forgejo unable to start is exactly the failure this sync
                // exists to prevent. Reconcile the existing one instead.
                $refreshTimeBefore = $this->externalSecretRefreshTime($kubectl, $ns, 'forgejo');
                $this->forceExternalSecretReconcile($kubectl, $ns, 'forgejo');

                return $this->waitForExternalSecretSynced($kubectl, $ns, 'forgejo', $refreshTimeBefore);
            },
        );

        if (! $ok) {
            $this->laraKubeError(
                "Forgejo is installed, but {$key} could not be stored in the cluster — "
                .'`larakube plex:rotate` will not be able to rotate this tenant until it is.',
            );
        }

        // Only a SUCCESSFUL sync flips the manifest onto the cluster-backed
        // key. Pointing the Deployment at a Secret that was never created would
        // leave FORGEJO__database__PASSWD unset and Forgejo unable to start.
        return $ok;
    }

    protected function readForgejoSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'forgejo-admin', $key);
    }

    /** Parse admin password from existing secret */
    protected function readExistingAdminPassword(string $kubectl, string $ns): ?string
    {
        return $this->readForgejoSecret($kubectl, $ns, 'password');
    }

    /** Decide which environment this install targets */
    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::GIT);
    }
}
