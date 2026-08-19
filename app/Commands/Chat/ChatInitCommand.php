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
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesToolFirewallPorts;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolBranding;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\SchedulesCronJobs;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class ChatInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithChat, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, ManagesToolFirewallPorts, RequiresFlagsWhenNonInteractive, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, SchedulesCronJobs, StreamsProcessOutput;

    protected $signature = 'chat:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Chat (example.com → prefix.example.com)}
        {--app-name= : Custom branding name for Cinny UI (defaults to Chat)}
        {--logo-url= : Custom logo URL for Cinny UI}
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

        if ($oidc !== null) {
            $this->line('  <fg=green>✓</> <fg=gray>SSO provider:</>   <fg=blue>'.$oidc['name'].'</>');
        } else {
            $this->line('  <fg=gray>Identity Provider SSO:</>        <fg=blue>larakube sso:wire chat</>');
        }

        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::CHAT);
    }
}
