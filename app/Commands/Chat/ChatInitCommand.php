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
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class ChatInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithChat, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, ManagesToolFirewallPorts, RequiresFlagsWhenNonInteractive, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'chat:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Chat (example.com → prefix.example.com)}
        {--app-name= : Custom branding name for Cinny UI (defaults to Chat)}
        {--logo-url= : Custom logo URL for Cinny UI}
        {--no-plex   : Bypass Plex Commons and bundle dedicated storage}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--no-host-port : Skip hostPort on Coturn/LiveKit — use on managed K8s with a real LoadBalancer}
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
        $livekitApiKey = $this->readChatSecret($kubectl, $ns, 'livekit-api-key') ?? 'LK_'.Str::random(16);
        $livekitApiSecret = $this->readChatSecret($kubectl, $ns, 'livekit-api-secret') ?? Str::random(32);

        $dbName = 'chat_matrix';
        $dbUser = 'chat_matrix';

        if (! $noPlex) {
            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
                return 1;
            }
            $this->allocateStorageBucket(StorageDriver::SEAWEEDFS, 'chat-media');
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $registrationSecret, $turnSecret, $livekitApiKey, $livekitApiSecret) {
            Process::run(
                "{$kubectl} create secret generic chat-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=registration-secret='.escapeshellarg($registrationSecret).' '
                .'--from-literal=turn-secret='.escapeshellarg($turnSecret).' '
                .'--from-literal=livekit-api-key='.escapeshellarg($livekitApiKey).' '
                .'--from-literal=livekit-api-secret='.escapeshellarg($livekitApiSecret).' '
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
            's3AccessKey' => 'seaweedfs',
            's3SecretKey' => 'seaweedfs',
            'dbName' => $dbName,
            'dbUser' => $dbUser,
            'dbPassword' => $dbPassword,
            'registrationSecret' => $registrationSecret,
            'turnSecret' => $turnSecret,
            'livekitApiKey' => $livekitApiKey,
            'livekitApiSecret' => $livekitApiSecret,
            'hostPort' => ! $this->option('no-host-port'),
            'externalIp' => $this->toolFirewallCloud($env)?->ip ?? gethostbyname($host),
            'smtp' => $smtp,
            'oidc' => $oidc,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-chat.yaml';
        file_put_contents($tmp, $manifest);

        $engineLabel = 'Matrix (Synapse + Element)';
        $this->withSpin("Applying {$engineLabel} manifests...", fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin("Waiting for {$engineLabel}...", fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/chat-synapse -n {$ns} --timeout=180s",
        ));

        $this->registerDeployedTool(ClusterTool::CHAT, $kubectl, $host);

        // On a cloud VPS, punch Coturn's/LiveKit's raw UDP/TCP ports through
        // both firewall layers (DO cloud edge + host UFW) — klipper binds them
        // via hostPort, but both default-deny, so calls silently never connect.
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
