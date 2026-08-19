<?php

namespace App\Commands\Notes;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithNotes;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class NotesInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithNotes, InteractsWithPlex, InteractsWithSso, InteractsWithZitadelApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

    /** How Outline's OIDC credentials were resolved this run, for the summary. */
    protected string $oidcSource = 'existing';

    protected $signature = 'notes:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Outline (example.com → prefix.example.com). Omit to target/update the default instance; pass a different host to deploy an ADDITIONAL instance there — the host you give IS its identity}
        {--alias=*    : Additional domain alias(es) to register on this instance\'s Ingress}
        {--admin-email= : Primary administrator email for Outline}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Outline wiki / knowledge base stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployNotes();
    }

    protected function deployNotes(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->notesKubectl($context);

        // --domain here means "this exact host" (see ResolvesToolHost::sanitizeDomainInput()
        // — no auto-prefixing), so it can double as the instance identifier.
        // No --domain given → ask which registered instance to target, or
        // offer to create a new one, rather than guessing (see
        // resolveInstanceAwareHost()'s docblock).
        [$host, $instance] = $this->resolveInstanceAwareHost(SharedClusterService::NOTES, ClusterTool::NOTES, $env, $kubectl);

        $ns = $this->notesNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::NOTES, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Outline requires Postgres + Redis from Plex Commons.
        if (! $this->ensureCommons(['postgres', 'redis'])) {
            return 1;
        }

        // S3 for file uploads — detect which backend the Commons provides.
        $spec = $this->getCommonsSpec();
        $s3Service = null;
        if ($spec !== null) {
            $enabled = $this->enabledCommonsServices($spec);
            if (in_array('seaweedfs', $enabled, true)) {
                $s3Service = 'seaweedfs';
            } elseif (in_array('minio', $enabled, true)) {
                $s3Service = 'minio';
            }
        }

        $s3Service ??= 'seaweedfs';
        if (! $this->ensureCommons([$s3Service])) {
            return 1;
        }

        $creds = $this->readCommonsS3Credentials();
        if ($creds === null) {
            $this->laraKubeError('Commons S3 credentials not found. Re-run `larakube plex:init`.');

            return 1;
        }

        $s3AccessKey = $creds['access'];
        $s3SecretKey = $creds['secret'];
        $deploymentName = ClusterTool::NOTES->deploymentName($instance);
        // The manifest's Service/Ingress default to the bare 'notes' name when
        // this isn't passed — fine for main, but a second instance applying
        // that same default would silently steal main's Service selector and
        // Ingress host rule instead of getting its own. Every other
        // multi-instance tool (data, chat, ...) already suffixes its
        // Service/Ingress name by instance; Outline just never did.
        $serviceName = "notes-{$instance}";
        $secretName = "notes-secrets-{$instance}";
        $oidcSecretName = "notes-outline-oidc-{$instance}";
        $dbName = ClusterTool::NOTES->commonsDatabases($instance)[0];
        $s3Bucket = "notes-storage-{$instance}";
        $aliasHosts = $this->resolveToolAliasHosts($kubectl, ClusterTool::NOTES, $instance);

        $driver = StorageDriver::from($s3Service);

        if (! $this->allocateStorageBucket($driver, $s3Bucket)) {
            return 1;
        }

        // AWS_S3_UPLOAD_BUCKET_URL is Outline's ONE S3 endpoint config — it
        // both drives the server's own S3 calls AND is signed into the
        // presigned upload/download URLs handed straight to the browser.
        $s3Endpoint = $this->resolveCommonsS3Endpoints($driver, 'Outline')['public'];

        // Stable secrets across re-runs — rotating these invalidates sessions.
        $adminEmail = $this->readNotesSecretKey($kubectl, $ns, $secretName, 'admin-email') ?? $this->resolveAdminEmail($host);
        $dbPassword = $this->readNotesSecretKey($kubectl, $ns, $secretName, 'db-password') ?? Str::random(24);
        $secretKey = $this->readNotesSecretKey($kubectl, $ns, $secretName, 'secret-key') ?? bin2hex(random_bytes(32));
        $utilsSecret = $this->readNotesSecretKey($kubectl, $ns, $secretName, 'utils-secret') ?? bin2hex(random_bytes(32));

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $redisIndex = $this->allocateCommonsRedisIndex($dbName);
        if ($redisIndex === null) {
            $this->laraKubeError('The Commons Valkey has no free logical DB index (all 16 in use).');

            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $secretName, $dbPassword, $secretKey, $utilsSecret): void {
            Process::run(
                "{$kubectl} create secret generic {$secretName} -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=secret-key='.escapeshellarg($secretKey).' '
                .'--from-literal=utils-secret='.escapeshellarg($utilsSecret).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        // Outline requires at least one authentication provider to start.
        if (! $this->ensureOidcSecret($kubectl, $ns, $env, $host, $instance, $oidcSecretName, $aliasHosts)) {
            return 1;
        }

        $manifest = view('k8s.notes.shared', [
            'host' => $host,
            'aliasHosts' => $aliasHosts,
            'deploymentName' => $deploymentName,
            'serviceName' => $serviceName,
            'secretName' => $secretName,
            'oidcSecretName' => $oidcSecretName,
            'dbUser' => $dbName,
            'dbName' => $dbName,
            'plexNamespace' => $this->plexNamespace(),
            'redisIndex' => $redisIndex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            's3Endpoint' => $s3Endpoint,
            's3Bucket' => $s3Bucket,
            's3AccessKey' => $s3AccessKey,
            's3SecretKey' => $s3SecretKey,
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path("larakube-notes-{$instance}.yaml");
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Outline wiki manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $deploymentName, 180),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::NOTES, $kubectl, $host, instance: $instance, extra: ['adminEmail' => $adminEmail]);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Outline wiki stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Cache/queue:</> <fg=blue>Commons Valkey</> · logical DB index <fg=blue>'.$redisIndex.'</>');
        $this->newLine();
        $authLine = match ($this->oidcSource) {
            'zitadel' => 'Wired to Zitadel SSO — sign in at the URL above.',
            'external' => 'Wired to your external OIDC provider.',
            default => 'Using the existing OIDC configuration.',
        };
        $this->line("  <fg=gray>Authentication:</> <fg=blue>{$authLine}</>");
        $this->newLine();

        return 0;
    }

    /**
     * Ensure the notes-outline-oidc secret exists with real credentials.
     *
     * Resolution order:
     *  1. Secret exists with non-pending values → reuse (self-wire or external).
     *  2. Zitadel is installed → register the OIDC app AND write the secret here,
     *     before the Outline Deployment exists. sso:wire can't do this — it
     *     requires the Deployment to already be running — and Outline can't
     *     start without OIDC, so notes:init owns the bootstrap itself.
     *  3. External SSO → prompt for the five OIDC fields, create the secret.
     *
     * The secret keys are the Outline env-var names (OIDC_CLIENT_ID, …), which
     * is what both the manifest's valueFrom and sso:wire's own convention use —
     * so a later `sso:wire notes` reuses this app instead of clashing.
     */
    protected function ensureOidcSecret(string $kubectl, string $ns, string $env, string $host, string $instance = 'main', string $oidcSecretName = 'notes-outline-oidc', array $aliasHosts = []): bool
    {
        // 1. Existing real credentials?
        $existing = $this->readClusterSecretKey($kubectl, $ns, $oidcSecretName, 'OIDC_CLIENT_ID');
        if ($existing !== null && $existing !== 'pending') {
            $this->laraKubeInfo('Reusing existing OIDC credentials for Outline.');

            return true;
        }

        // 2. Zitadel installed → self-wire it (register app + write secret).
        if ($this->isSsoInstalled($kubectl, $this->ssoNamespace())) {
            return $this->selfWireZitadel($kubectl, $ns, $env, $host, $instance, $oidcSecretName, $aliasHosts);
        }

        // 3. External SSO — prompt for OIDC details.
        $this->newLine();
        $this->line('  <fg=yellow>Outline requires an OIDC provider for login.</>');
        $this->line('  No Zitadel installation detected. You can:');
        $this->newLine();
        $this->line('    1. Install Zitadel:  <fg=blue>larakube sso:init</> (notes:init then wires it for you)');
        $this->line('    2. Provide external OIDC details below');
        $this->newLine();

        $source = select(
            label: 'How would you like to authenticate Outline?',
            options: [
                'external' => 'I have an external OIDC provider',
                'zitadel' => 'Install Zitadel first (run sso:init)',
            ],
        );

        if ($source === 'zitadel') {
            $this->line('  Run <fg=blue>larakube sso:init</>, then re-run <fg=blue>larakube notes:init</> — it wires Zitadel automatically.');

            return false;
        }

        $clientId = text(label: 'OIDC Client ID', required: true);
        $clientSecret = text(label: 'OIDC Client Secret', required: true);
        $authUrl = text(label: 'OIDC Authorization URL', placeholder: 'https:// provider.example.com/oauth/authorize', required: true);
        $tokenUrl = text(label: 'OIDC Token URL', placeholder: 'https:// provider.example.com/oauth/token', required: true);
        $userinfoUrl = text(label: 'OIDC UserInfo URL', placeholder: 'https:// provider.example.com/oidc/userinfo', required: true);

        $this->writeNotesOidcSecret($kubectl, $ns, $oidcSecretName, [
            'OIDC_CLIENT_ID' => $clientId,
            'OIDC_CLIENT_SECRET' => $clientSecret,
            'OIDC_AUTH_URI' => $authUrl,
            'OIDC_TOKEN_URI' => $tokenUrl,
            'OIDC_USERINFO_URI' => $userinfoUrl,
        ]);

        $this->oidcSource = 'external';
        $this->laraKubeInfo('OIDC secret created for Outline.');

        return true;
    }

    protected function selfWireZitadel(string $kubectl, string $ns, string $env, string $host, string $instance = 'main', string $oidcSecretName = 'notes-outline-oidc', array $aliasHosts = []): bool
    {
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $ssoHost = $this->resolveSsoHostReadOnly($env, $config, $kubectl);
        if ($ssoHost === null) {
            $this->laraKubeError("Zitadel is installed but its host for '{$env}' could not be resolved — re-run `larakube sso:init {$env}`.");

            return false;
        }

        $pat = $this->readSsoSecret($kubectl, $this->ssoNamespace(), 'machine-pat');
        if ($pat === null) {
            $this->laraKubeError('Could not read Zitadel automation credentials — re-run `larakube sso:init`.');

            return false;
        }

        $tool = ClusterTool::NOTES;
        $appName = "{$tool->productName()} ({$instance})";
        $registered = null;
        $this->withSpin("Registering Outline ({$instance}) as an OIDC client in Zitadel...", function () use (&$registered, $ssoHost, $pat, $tool, $host, $aliasHosts, $appName): void {
            $projectId = $this->zitadelEnsureProject($ssoHost, $pat, 'LaraKube Shared Tools');
            if ($projectId === null) {
                return;
            }

            $app = $this->zitadelCreateOidcApp($ssoHost, $pat, $projectId, $appName, $tool->oidcRedirectUris($host, $aliasHosts));
            if ($app === null) {
                return;
            }

            $registered = array_merge($app, ['projectId' => $projectId]);
        });

        if ($registered === null) {
            $this->laraKubeError('Could not register Outline in Zitadel — check the automation credentials and Zitadel\'s own logs.');

            return false;
        }

        $ssoAppSecret = "sso-app-notes-{$instance}";

        Process::run(
            "{$kubectl} create secret generic {$ssoAppSecret} -n ".$this->ssoNamespace().' '
            .'--from-literal=project-id='.escapeshellarg($registered['projectId']).' '
            .'--from-literal=app-id='.escapeshellarg($registered['appId']).' '
            .'--from-literal=client-id='.escapeshellarg($registered['clientId']).' '
            .'--from-literal=client-secret='.escapeshellarg($registered['clientSecret']).' '
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        );

        $this->writeNotesOidcSecret($kubectl, $ns, $oidcSecretName, [
            'OIDC_CLIENT_ID' => $registered['clientId'],
            'OIDC_CLIENT_SECRET' => $registered['clientSecret'],
            'OIDC_AUTH_URI' => "https://{$ssoHost}/oauth/v2/authorize",
            'OIDC_TOKEN_URI' => "https://{$ssoHost}/oauth/v2/token",
            'OIDC_USERINFO_URI' => "https://{$ssoHost}/oidc/v1/userinfo",
        ]);

        $this->oidcSource = 'zitadel';
        $this->laraKubeInfo("✅ Registered Outline ({$instance}) with Zitadel SSO.");

        return true;
    }

    protected function writeNotesOidcSecret(string $kubectl, string $ns, string $secretName, array $data): void
    {
        $literals = '';
        foreach ($data as $key => $value) {
            $literals .= '--from-literal='.$key.'='.escapeshellarg($value).' ';
        }

        $this->withSpin('Writing OIDC secret...', fn () => Process::run(
            "{$kubectl} create secret generic {$secretName} -n {$ns} {$literals}--dry-run=client -o yaml | {$kubectl} apply -f -",
        ));
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::NOTES);
    }

    /** Resolve the admin email for Outline */
    protected function resolveAdminEmail(string $host): string
    {
        $parts = explode('.', $host);
        $default = 'admin@'.(count($parts) >= 2 ? implode('.', array_slice($parts, 1)) : $host);

        return $this->flagOrPrompt(
            flag: 'admin-email',
            prompt: fn () => \Laravel\Prompts\text(
                label: 'Primary administrator email for Outline',
                default: $default,
                required: true,
            ),
            purpose: 'Primary administrator email for Outline',
            example: "--admin-email={$default}",
        );
    }
}
