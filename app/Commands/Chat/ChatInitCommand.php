<?php

namespace App\Commands\Chat;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class ChatInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithChat, InteractsWithClusterContext, InteractsWithPlex, LaraKubeOutput, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'chat:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Mattermost (example.com → prefix.example.com)}
        {--no-plex   : Bypass Plex Commons and bundle a dedicated Postgres + local file storage}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Deploy the Mattermost team-chat stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployChat();
    }

    protected function deployChat(): int
    {
        $env = $this->resolveEnvironment();
        $host = $this->resolveChatHost($env);
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->chatKubectl($context);
        $ns = $this->chatNamespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::CHAT, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $s3Endpoint = '';
        $s3Bucket = '';
        $s3AccessKey = '';
        $s3SecretKey = '';

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres'])) {
                return 1;
            }

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

            // Default to SeaweedFS (Apache-licensed) if the Commons doesn't
            // already offer an S3 backend.
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
            $s3Bucket = 'chat-storage';
            $driver = StorageDriver::from($s3Service);
            // Mattermost's S3 client expects a bare host:port endpoint — no scheme.
            // MM_FILESETTINGS_AMAZONS3SSL=false already controls http vs https.
            $s3Endpoint = "{$s3Service}.{$this->plexNamespace()}.svc.cluster.local:{$driver->port()}";

            if (! $this->allocateStorageBucket($driver, $s3Bucket)) {
                return 1;
            }
        }

        $dbPassword = $this->readChatSecret($kubectl, $ns, 'db-password') ?? Str::random(24);

        if (! $noPlex) {
            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, 'mattermost', $dbPassword)) {
                return 1;
            }
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword) {
            Process::run(
                "{$kubectl} create secret generic chat-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $manifest = view('k8s.chat.mattermost', [
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            's3Endpoint' => $s3Endpoint,
            's3Bucket' => $s3Bucket,
            's3AccessKey' => $s3AccessKey,
            's3SecretKey' => $s3SecretKey,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-chat.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Mattermost manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Mattermost...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/chat-mattermost -n {$ns} --timeout=180s",
            190,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Mattermost is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->newLine();
        $this->line('  <fg=yellow>First-run setup</> — the first person to open the URL and sign up');
        $this->line('  automatically becomes System Admin. There is no separate admin bootstrap step.');
        $this->newLine();
        $this->line('  <fg=gray>Outbound notification email:</> <fg=blue>larakube mail:wire chat</>');
        $this->newLine();

        return 0;
    }

    protected function resolveChatHost(string $env): string
    {
        $service = SharedClusterService::CHAT;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveChatHostReadOnly('local', null);
        }

        return $this->promptForCloudChatHost($service, $env);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::CHAT);
    }

    protected function promptForCloudChatHost(SharedClusterService $service, string $env): string
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
            placeholder: $default !== '' ? $default : 'e.g. chat.example.com',
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
