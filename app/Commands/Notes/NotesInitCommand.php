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
use App\Traits\InteractsWithNotes;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSso;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class NotesInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithNotes, InteractsWithPlex, InteractsWithSso, LaraKubeOutput, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'notes:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Outline (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Deploy the Outline wiki / knowledge base stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployNotes();
    }

    protected function deployNotes(): int
    {
        $env = $this->resolveEnvironment();
        $host = $this->resolveNotesHost($env);
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->notesKubectl($context);
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
        $s3Bucket = 'notes-storage';
        $driver = StorageDriver::from($s3Service);
        // Outline's S3 client expects a full URL endpoint.
        $scheme = in_array($s3Service, ['minio'], true) ? 'http' : 'http';
        $s3Endpoint = "{$scheme}://{$s3Service}.{$this->plexNamespace()}.svc.cluster.local:{$driver->port()}";

        if (! $this->allocateStorageBucket($driver, $s3Bucket)) {
            return 1;
        }

        // Stable secrets across re-runs — rotating these invalidates sessions.
        $dbPassword = $this->readNotesSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $secretKey = $this->readNotesSecret($kubectl, $ns, 'secret-key') ?? bin2hex(random_bytes(32));
        $utilsSecret = $this->readNotesSecret($kubectl, $ns, 'utils-secret') ?? bin2hex(random_bytes(32));

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, 'outline', $dbPassword)) {
            return 1;
        }

        $redisIndex = $this->allocateCommonsRedisIndex('outline');
        if ($redisIndex === null) {
            $this->laraKubeError('The Commons Valkey has no free logical DB index (all 16 in use).');

            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $secretKey, $utilsSecret) {
            Process::run(
                "{$kubectl} create secret generic notes-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=secret-key='.escapeshellarg($secretKey).' '
                .'--from-literal=utils-secret='.escapeshellarg($utilsSecret).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        // Outline requires at least one authentication provider to start.
        if (! $this->ensureOidcSecret($kubectl, $ns, $env)) {
            return 1;
        }

        $manifest = view('k8s.notes.shared', [
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'redisIndex' => $redisIndex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            's3Endpoint' => $s3Endpoint,
            's3Bucket' => $s3Bucket,
            's3AccessKey' => $s3AccessKey,
            's3SecretKey' => $s3SecretKey,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-notes.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Outline wiki manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Outline...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/notes-outline -n {$ns} --timeout=180s",
            190,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Outline wiki stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Cache/queue:</> <fg=blue>Commons Valkey</> · logical DB index <fg=blue>'.$redisIndex.'</>');
        $this->newLine();
        $this->line('  <fg=yellow>Authentication</> — Outline requires an external OIDC provider for login.');
        if ($this->isSsoInstalled($kubectl, 'larakube-sso')) {
            $this->line('  Run <fg=blue>larakube tool:add notes</> to wire Zitadel SSO automatically.');
        } else {
            $this->line('  Run <fg=blue>larakube sso:init</> to install Zitadel, then <fg=blue>larakube tool:add notes</> to wire it.');
        }
        $this->newLine();

        return 0;
    }

    /**
     * Ensure the notes-outline-oidc secret exists with real credentials.
     *
     * Resolution order:
     *  1. Secret exists with non-pending values → reuse (sso:wire or external).
     *  2. Zitadel is installed → tell user to run sso:wire, abort.
     *  3. External SSO → prompt for the five OIDC fields, create the secret.
     */
    protected function ensureOidcSecret(string $kubectl, string $ns, string $env): bool
    {
        // 1. Existing real credentials?
        $existing = $this->readNotesNamedSecret($kubectl, $ns, 'notes-outline-oidc', 'client-id');
        if ($existing !== null && $existing !== 'pending') {
            $this->laraKubeInfo('Reusing existing OIDC credentials for Outline.');

            return true;
        }

        // 2. Zitadel installed?
        if ($this->isSsoInstalled($kubectl, 'larakube-sso')) {
            $this->newLine();
            $this->line('  <fg=yellow>Outline requires an OIDC provider for login.</>');
            $this->line('  Zitadel is already installed on this cluster.');
            $this->newLine();
            $this->line('  Run <fg=blue>larakube sso:wire notes</>, then re-run <fg=blue>larakube notes:init</>.');
            $this->newLine();

            return false;
        }

        // 3. External SSO — prompt for OIDC details.
        $this->newLine();
        $this->line('  <fg=yellow>Outline requires an OIDC provider for login.</>');
        $this->line('  No Zitadel installation detected. You can:');
        $this->newLine();
        $this->line('    1. Install Zitadel:  <fg=blue>larakube sso:init</> then <fg=blue>larakube sso:wire notes</>');
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
            $this->line('  Run <fg=blue>larakube sso:init</>, then <fg=blue>larakube sso:wire notes</>, then re-run <fg=blue>larakube notes:init</>.');

            return false;
        }

        $clientId = text(label: 'OIDC Client ID', required: true);
        $clientSecret = text(label: 'OIDC Client Secret', required: true);
        $authUrl = text(label: 'OIDC Authorization URL', placeholder: 'https:// provider.example.com/oauth/authorize', required: true);
        $tokenUrl = text(label: 'OIDC Token URL', placeholder: 'https:// provider.example.com/oauth/token', required: true);
        $userinfoUrl = text(label: 'OIDC UserInfo URL', placeholder: 'https:// provider.example.com/oidc/userinfo', required: true);

        $this->withSpin('Creating OIDC secret...', function () use ($kubectl, $ns, $clientId, $clientSecret, $authUrl, $tokenUrl, $userinfoUrl) {
            Process::run(
                "{$kubectl} create secret generic notes-outline-oidc -n {$ns} "
                .'--from-literal=client-id='.escapeshellarg($clientId).' '
                .'--from-literal=client-secret='.escapeshellarg($clientSecret).' '
                .'--from-literal=auth-url='.escapeshellarg($authUrl).' '
                .'--from-literal=token-url='.escapeshellarg($tokenUrl).' '
                .'--from-literal=userinfo-url='.escapeshellarg($userinfoUrl).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $this->laraKubeInfo('OIDC secret created for Outline.');

        return true;
    }

    /**
     * Read a key from an arbitrary secret (unlike readNotesSecret which is
     * pinned to notes-secrets).
     */
    protected function readNotesNamedSecret(string $kubectl, string $ns, string $secret, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret {$secret} -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    protected function resolveNotesHost(string $env): string
    {
        $service = SharedClusterService::NOTES;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveNotesHostReadOnly('local', null);
        }

        return $this->promptForCloudNotesHost($service, $env);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::NOTES);
    }

    protected function promptForCloudNotesHost(SharedClusterService $service, string $env): string
    {
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $existing = $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
        if ($existing) {
            return $existing;
        }

        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        $default = ($config && $webHost) ? $config->getSharedServiceHost($service, $env) : '';

        $host = text(
            label: "What host should {$service->label()} use in '{$env}'?",
            placeholder: $default !== '' ? $default : 'e.g. notes.example.com',
            default: $default,
            required: true,
        );

        if ($config) {
            $config->setHost($env, $service->value, $host);
            $config->saveToFile($projectPath);
            $this->laraKubeInfo("Saved {$service->label()} host for '{$env}' to .larakube.json");
        }

        return $host;
    }
}
