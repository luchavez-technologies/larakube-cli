<?php

namespace App\Commands\Paste;

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPaste;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Deploys Yopass — zero-knowledge, no-account, one-time-read secret sharing.
 * Redis is a hard requirement (Yopass has no SQL backend at all, and the
 * only other option is bundling a dedicated Memcached, which would defeat
 * the whole point of reusing the Commons); the optional S3 file-storage
 * feature is wired to Commons SeaweedFS/MinIO/Garage only when one is
 * enabled, mirroring MailInitCommand's own conditional store wiring.
 */
class PasteInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPaste, InteractsWithPlex, LaraKubeOutput, RefusesUnshippedTools, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

    protected $signature = 'paste:init
        {environment? : Environment this install targets — "local" (default) or a cloud env.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Yopass (example.com → paste.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting — WARNING: this tool exists to receive a paste from an external, unauthenticated partner; --vpn-only blocks exactly that. Only use it for an internal-scratchpad-only install.}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy Yopass (secure, one-time-read paste sharing) into larakube-shared';

    public function handle(): int
    {
        if ($this->refuseUnshippedTool(ClusterTool::PASTE)) {
            return 1;
        }

        $this->renderHeader();

        return $this->deployPaste();
    }

    protected function deployPaste(): int
    {
        $env = $this->resolveToolEnvironment(ClusterTool::PASTE);
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->pasteKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::PASTE, ClusterTool::PASTE, $env, $kubectl);
        // Every tool's instance identifier is a real, host-derived slug now
        // — Paste included, even though it's a simple, always-single-instance
        // stateless tool.
        $instance = ClusterTool::PASTE->instanceSlugFromHost($host);
        $ns = $this->pasteNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->assertVpnOnlySupported(ClusterTool::PASTE)) {
            return 1;
        }

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::PASTE, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Redis is not optional here — Yopass has no other lightweight
        // backend, and bundling a dedicated Memcached would defeat the
        // point of reusing the Commons.
        if (! $this->ensureCommons(['redis'])) {
            return 1;
        }

        $plexNs = $this->plexNamespace();
        $redisIndex = $this->allocateCommonsRedisIndex('paste_yopass');

        if ($redisIndex === null) {
            $this->laraKubeError('Every Commons Redis index (0-15) is already allocated — free one up (larakube <tool>:remove --purge) and retry.');

            return 1;
        }

        $fileStorage = $this->wireOptionalFileStorage($kubectl, $ns, $plexNs, $instance);

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $manifest = view('k8s.paste.shared', [
            'host' => $host,
            'instance' => $instance,
            'plexNamespace' => $plexNs,
            'redisIndex' => $redisIndex,
            'fileStorage' => $fileStorage,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-paste-yopass.yaml');
        file_put_contents($tmp, $manifest);

        $deploymentName = ClusterTool::PASTE->deploymentName($instance);

        $rolledOut = $this->withSpin(
            'Applying Yopass manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, $deploymentName, 120),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::PASTE, $kubectl, $host, $instance);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Yopass is live.');
        $this->newLine();
        $this->line("  <fg=gray>URL:</>          <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Storage:</>      Commons Redis (index '.$redisIndex.')'.($fileStorage !== null ? ' + Commons S3 file uploads' : ''));
        $this->line('  <fg=gray>Behaviour:</>    Every paste self-destructs the moment it\'s read, or when it expires (1 day by default) — whichever comes first. No account, no login, nothing to configure.');
        $this->newLine();
        $this->line('  Share the URL above with anyone who needs to hand you a secret — they paste it, get a link back, send you that link. You open it once.');
        $this->newLine();

        return 0;
    }

    /**
     * Wire Yopass's S3 file-storage feature to Commons SeaweedFS/MinIO/Garage
     * when Commons offers one — this is separate from (and does not require)
     * the Redis secret store, purely for letting a sender attach a larger
     * file alongside a short secret. Returns null (Redis-only, still fully
     * functional) when no S3 driver is enabled on this Commons.
     *
     * @return array{bucket: string, endpoint: string, region: string}|null
     */
    protected function wireOptionalFileStorage(string $kubectl, string $ns, string $plexNs, string $instance): ?array
    {
        $spec = $this->getCommonsSpec();
        if ($spec === null) {
            return null;
        }

        $services = $this->enabledCommonsServices($spec);
        $bucket = 'paste-yopass';

        foreach (['seaweedfs', 'minio', 'garage'] as $candidate) {
            if (! in_array($candidate, $services, true)) {
                continue;
            }

            $driver = StorageDriver::tryFrom($candidate);
            $s3Creds = $this->readCommonsS3Credentials();
            if ($driver === null || $s3Creds === null || ! $this->allocateStorageBucket($driver, $bucket)) {
                break;
            }

            $this->withSpin('Syncing S3 file-storage credentials...', function () use ($kubectl, $ns, $instance, $s3Creds): void {
                $cmd = "{$kubectl} create secret generic paste-yopass-secrets-{$instance} -n {$ns} "
                    .'--from-literal=s3-access-key='.escapeshellarg($s3Creds['access']).' '
                    .'--from-literal=s3-secret-key='.escapeshellarg($s3Creds['secret']).' '
                    ."--dry-run=client -o yaml | {$kubectl} apply -f -";
                Process::run($cmd);
            });

            return [
                'bucket' => $bucket,
                'endpoint' => "http://{$candidate}.{$plexNs}.svc.cluster.local:8333",
                'region' => 'us-east-1',
            ];
        }

        return null;
    }
}
