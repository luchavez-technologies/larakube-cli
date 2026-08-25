<?php

namespace App\Commands\Git;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
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
use App\Traits\ResolvesToolBranding;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class GitInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithGitForge, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, ManagesToolFirewallPorts, RequiresFlagsWhenNonInteractive, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

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
        {--app-name= : Custom branding name for Forgejo (defaults to Git)}
        {--logo-url= : Custom logo URL for Forgejo}
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

        $host = $this->resolveToolHost(SharedClusterService::GITEA, ClusterTool::GIT, $env, $kubectl);
        // Every tool's instance identifier is a real, host-derived slug — Git
        // included, even though it's an architectural singleton (Forgejo's
        // SSH LoadBalancer binds a fixed port, so a genuine second instance is
        // impossible on one node regardless of naming).
        $instance = ClusterTool::GIT->instanceSlugFromHost($host);
        $tenant = ClusterTool::GIT->commonsDatabases($instance)[0];
        $deployment = ClusterTool::GIT->deploymentName($instance);
        $buckets = ClusterTool::GIT->commonsBuckets($instance);

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
            if (! $this->allocateStorageBucket($driver, $buckets[0]) ||
                ! $this->allocateStorageBucket($driver, $buckets[1]) ||
                ! $this->allocateStorageBucket($driver, $buckets[2])) {
                return 1;
            }
        }

        // Read or generate password & secrets
        $adminPassword = $this->readExistingAdminPassword($kubectl, $ns, $instance);
        if ($adminPassword === null) {
            $adminPassword = Str::random(16);
        }

        $adminEmail = $this->readForgejoSecret($kubectl, $ns, $instance, 'admin-email')
            ?? $this->resolveAdminEmail($host);

        // Was reading the `password` key — i.e. the ADMIN password — so a re-run
        // silently reset the database role to the admin's password.
        $dbPassword = $this->readForgejoSecret($kubectl, $ns, $instance, 'db-password') ?? Str::random(24);

        $redisIndex = null;

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres', 'redis'])) {
                return 1;
            }
            // Once OpenBao's database secrets engine already owns the
            // tenant's static role, defer to ITS current password instead
            // of re-affirming a locally-cached one that may predate
            // OpenBao's own rotation — see resolveManagedDbPassword()'s
            // docblock. Confirmed live 2026-08-15: this exact gap took
            // Forgejo down after a routine OpenBao reseal/resync.
            $dbPassword = $this->resolveManagedDbPassword($kubectl, $tenant, $dbPassword);

            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $tenant, $dbPassword)) {
                return 1;
            }

            // Without this Gitea keeps sessions in files on its PVC and the cache
            // in memory, so every pod restart signs everyone out.
            $redisIndex = $this->allocateCommonsRedisIndex($tenant);
            if ($redisIndex === null) {
                $this->laraKubeError('The Commons Valkey has no free logical DB index (all 16 in use).');

                return 1;
            }
        }

        // 40 hex chars: first 16 are the runner identifier, last 24 the secret.
        // Read-or-generate: a fresh secret on every re-run would orphan the
        // previously registered runner.
        $runnerSecret = $this->readForgejoSecret($kubectl, $ns, $instance, 'runner-secret') ?? bin2hex(random_bytes(20));
        $oauthJwtSecret = $this->readForgejoSecret($kubectl, $ns, $instance, 'oauth-jwt-secret')
            ?? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // MUST be read before the first manifest apply below, not after: that
        // apply rewrites the Secret, so reading later would only ever see the
        // placeholder it just wrote. An access token's value is shown once, at
        // creation, and Forgejo has no `delete-access-token` — so this stored
        // copy is the ONLY copy, and losing it means the name is burned forever.
        $registryToken = $this->readForgejoSecret($kubectl, $ns, $instance, 'registry-token');
        if ($registryToken === 'pending' || $registryToken === '') {
            $registryToken = null;
        }

        // Read-or-generate, like every other credential above. These used to be
        // regenerated unconditionally, which rolled the pod on every re-run for
        // no reason — and worse, SECRET_KEY is what Forgejo encrypts stored data
        // with (2FA enrollments among it), so rotating it silently made that data
        // unreadable.
        $secretKey = $this->readForgejoSecret($kubectl, $ns, $instance, 'secret-key') ?? Str::random(16);
        $internalToken = $this->readForgejoSecret($kubectl, $ns, $instance, 'internal-token') ?? Str::random(16);
        // base64url, no padding: decodes to exactly the 32 bytes Forgejo wants.
        $jwtSecret = $this->readForgejoSecret($kubectl, $ns, $instance, 'lfs-jwt-secret')
            ?? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // Ensure namespace exists
        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $vpnOnly = (bool) $this->option('vpn-only');
        $branding = $this->resolveToolBranding($kubectl, ClusterTool::GIT);

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::GIT, $kubectl, $instance)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // 1. Initial deployment with Gitea Core only (runner token placeholder)
        $manifest = view('k8s.git.forgejo', [
            'host' => $host,
            'instance' => $instance,
            'appName' => $branding['appName'],
            'logoUrl' => $branding['logoUrl'],
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
            'buckets' => $buckets,
            'tenant' => $tenant,
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-forgejo.yaml');
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Forgejo core manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $deployment, 120),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        // 2. CLI commands inside pod to create user and get tokens.

        $this->withSpin('Initializing Forgejo admin user...', function () use ($kubectl, $ns, $deployment, $adminPassword, $adminEmail) {
            $list = Process::run("{$kubectl} exec deploy/{$deployment} -n {$ns} -- su-exec git forgejo --config /data/gitea/conf/app.ini admin user list")->output();
            if (str_contains($list, 'larakube')) {
                return true;
            }

            return Process::run(
                "{$kubectl} exec deploy/{$deployment} -n {$ns} -- ".
                'su-exec git forgejo --config /data/gitea/conf/app.ini admin user create '.
                '--username larakube --password '.escapeshellarg($adminPassword).' '.
                '--email '.escapeshellarg($adminEmail).' --admin',
            )->successful();
        });

        if ($registryToken === null) {
            $this->withSpin('Generating Forgejo package registry token...', function () use ($kubectl, $ns, $deployment, &$registryToken) {
                // Unique name per issue. Forgejo rejects a duplicate token name
                // ("access token name has been used already") and offers no way
                // to delete one from the CLI, so a fixed name would permanently
                // wedge this step the moment a token is lost.
                $name = 'larakube-registry-'.Str::lower(Str::random(6));

                $result = Process::run(
                    "{$kubectl} exec deploy/{$deployment} -n {$ns} -- ".
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
        $this->withSpin('Registering the Forgejo Actions runner...', function () use ($kubectl, $ns, $deployment, $runnerSecret, &$registered) {
            $registered = Process::run(
                "{$kubectl} exec deploy/{$deployment} -n {$ns} -- ".
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
            'instance' => $instance,
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
            'buckets' => $buckets,
            'tenant' => $tenant,
        ])->render();

        $finalTemporaryDirectory = TemporaryDirectory::make();
        $tmpFinal = $finalTemporaryDirectory->path('larakube-forgejo-final.yaml');
        file_put_contents($tmpFinal, $manifestFinal);

        // Explicit ->timeout() on both: Laravel's default PHP-level Process
        // timeout is 60s, under kubectl's own --timeout flags below — without
        // it a slow rollout throws a ProcessTimedOutException and crashes
        // this command before kubectl's own timeout ever fires (confirmed
        // live on Documenso, 2026-08-05).
        $finalApplied = $this->withSpin(
            'Applying Forgejo Actions runner...',
            fn () => Process::timeout(70)->run("{$kubectl} apply -f {$tmpFinal} --request-timeout=60s")->successful(),
        );
        $finalTemporaryDirectory->delete();

        if (! $finalApplied) {
            $this->laraKubeError('Could not apply the final Forgejo manifest — see the output above.');

            return 1;
        }

        $runnerDeployment = ClusterTool::GIT->componentByKey('runner', $instance)->deployment;

        if ($registered && ! $this->withSpin('Waiting for Actions Runner...', fn () => Process::timeout(130)->run(
            "{$kubectl} rollout status deploy/{$runnerDeployment} -n {$ns} --timeout=120s",
        )->successful())) {
            $this->laraKubeError("{$runnerDeployment} never became Ready.");

            return 1;
        }

        // The forgejo-ssh Service is a LoadBalancer on 2222, but both the cloud
        // firewall and the host UFW default-deny it — without this, `git clone
        // ssh://…` hangs against a Service that looks perfectly healthy.
        $this->openToolPorts(SharedClusterService::GITEA, $env);

        // Forgejo never registered itself here — the only registry write it
        // ever got was an incidental side effect of resolveToolBranding()
        // saving a custom --app-name/--logo-url, which only fires when one
        // was actually passed. Every plain `git:init` left the tool entirely
        // absent from the registry: no host, so tool:list/tool:show and any
        // `git:` -domain targeting had nothing to find.
        $this->registerDeployedTool(ClusterTool::GIT, $kubectl, $host, extra: ['adminEmail' => $adminEmail]);

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
        $parts = explode('.', $host);
        $default = 'admin@'.(count($parts) >= 2 ? implode('.', array_slice($parts, 1)) : $host);

        return $this->flagOrPrompt(
            flag: 'admin-email',
            prompt: fn () => text(
                label: 'Admin email for the Forgejo account',
                default: $default,
                required: true,
            ),
            purpose: 'Admin email for Forgejo',
            example: "--admin-email={$default}",
        );
    }

    /** Read any key from the git-secrets-{instance} secret; null when absent. */
    protected function readForgejoSecret(string $kubectl, string $ns, string $instance, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, "git-secrets-{$instance}", $key);
    }

    /** Parse admin password from existing secret */
    protected function readExistingAdminPassword(string $kubectl, string $ns, string $instance): ?string
    {
        return $this->readForgejoSecret($kubectl, $ns, $instance, 'password');
    }

    /** Decide which environment this install targets */
    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::GIT);
    }
}
