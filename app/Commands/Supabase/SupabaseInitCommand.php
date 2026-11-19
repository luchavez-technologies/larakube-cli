<?php

namespace App\Commands\Supabase;

use App\Commands\Tool\AbstractToolInitCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Services\Kubectl;
use App\Support\SupabaseKeys;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\multiselect;

use Spatie\TemporaryDirectory\TemporaryDirectory;

abstract class SupabaseInitCommand extends AbstractToolInitCommand
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithSecrets, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected function runInit(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = ClusterTool::SUPABASE->namespace();

        [$host, $instance] = $this->resolveInstanceAwareHost(SharedClusterService::DATA, ClusterTool::SUPABASE, $env, $kubectl, 'Supabase');
        $names = ToolInstance::forInstance(ClusterTool::SUPABASE, $instance);
        $isLocal = $env === 'local';
        $vpnOnly = (bool) $this->option('vpn-only');
        $proxied = (bool) $this->option('proxied');
        $noPlex = (bool) $this->option('no-plex');

        [$withRealtime, $withFunctions, $withImgproxy, $withPooler] = $this->resolveComponents();

        Process::run("{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -");

        $keys = $this->resolveIdempotentKeys($kubectl, $ns, $names, $env);

        $s3Bucket = 'supabase';
        $s3Endpoint = '';
        $s3AccessKey = '';
        $s3SecretKey = '';
        $s3Region = 'us-east-1';

        if (! $noPlex) {
            $s3Bucket = $names->bucket();
            $this->allocateStorageBucket(StorageDriver::SEAWEEDFS, $s3Bucket);
            $s3Creds = $this->readCommonsS3Credentials();
            $s3Endpoint = (string) ($s3Creds['endpoint'] ?? "http://seaweedfs.{$this->plexNamespace()}.svc.cluster.local:8333");
            $s3AccessKey = (string) ($s3Creds['access'] ?? 'seaweedfs-access-key');
            $s3SecretKey = (string) ($s3Creds['secret'] ?? 'seaweedfs-secret-key');
            $s3Region = (string) ($s3Creds['region'] ?? 'us-east-1');
        }

        $vars = [
            'instance' => $instance,
            'host' => $host,
            'isLocal' => $isLocal,
            'vpnOnly' => $vpnOnly,
            'proxied' => $proxied,
            'noPlex' => $noPlex,
            's3Bucket' => $s3Bucket,
            's3Endpoint' => $s3Endpoint,
            's3AccessKey' => $s3AccessKey,
            's3SecretKey' => $s3SecretKey,
            's3Region' => $s3Region,
            'withRealtime' => $withRealtime,
            'withFunctions' => $withFunctions,
            'withImgproxy' => $withImgproxy,
            'withPooler' => $withPooler,
        ];

        $manifest = implode("\n---\n", [
            view('k8s.supabase.db', $vars)->render(),
            view('k8s.supabase.core', $vars)->render(),
            view('k8s.supabase.optional', $vars)->render(),
            view('k8s.supabase.gateway', $vars)->render(),
            view('k8s.supabase.ingress', $vars)->render(),
        ]);

        $tempDir = TemporaryDirectory::make();
        $manifestFile = $tempDir->path('supabase.yaml');
        file_put_contents($manifestFile, $manifest);

        if (! $this->applyAndVerifyRollout(
            kubectl: $kubectl,
            manifestPath: $manifestFile,
            namespace: $ns,
            deployment: $names->deployment(),
            tool: ClusterTool::SUPABASE,
        )) {
            return 1;
        }

        $this->enableVectorExtension($kubectl, $ns, $names->name('db'));

        $this->registerDeployedTool(ClusterTool::SUPABASE, $kubectl, $host, $instance, [
            'noPlex' => $noPlex,
        ]);

        $this->laraKubeInfo("Supabase is ready at https://{$host} (Studio basic auth user: {$keys['dashboard-username']}).");

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::SUPABASE);
    }

    /** @return array{bool, bool, bool, bool} */
    private function resolveComponents(): array
    {
        $noRealtime = (bool) $this->option('no-realtime');
        $noFunctions = (bool) $this->option('no-functions');
        $noImgproxy = (bool) $this->option('no-imgproxy');
        $noPooler = (bool) $this->option('no-pooler');

        if ($this->cannotPrompt() || (bool) $this->option('force') || $noRealtime || $noFunctions || $noImgproxy || $noPooler) {
            return [! $noRealtime, ! $noFunctions, ! $noImgproxy, ! $noPooler];
        }

        $selected = multiselect(
            label: 'Optional Supabase components',
            options: [
                'realtime' => 'Realtime (WebSockets, ~256Mi)',
                'functions' => 'Edge Functions (Deno runtime, ~256Mi)',
                'imgproxy' => 'Imgproxy (image transforms, ~128Mi)',
                'pooler' => 'Supavisor (connection pooler, ~256Mi)',
            ],
            default: ['realtime', 'functions', 'imgproxy', 'pooler'],
        );

        return [
            in_array('realtime', $selected, true),
            in_array('functions', $selected, true),
            in_array('imgproxy', $selected, true),
            in_array('pooler', $selected, true),
        ];
    }

    /** @return array<string, string> */
    private function resolveIdempotentKeys(string $kubectl, string $ns, ToolInstance $names, string $env): array
    {
        $secretName = $names->secret();
        $existing = Kubectl::fromPrefix($kubectl)->raw(['get', 'secret', $secretName, '-n', $ns, '-o', 'json', '--ignore-not-found'])->json();
        $existingData = is_array($existing['data'] ?? null) ? $existing['data'] : [];

        $keys = SupabaseKeys::generate();
        foreach ($keys as $k => $v) {
            if (isset($existingData[$k]) && is_string($existingData[$k]) && $existingData[$k] !== '') {
                $keys[$k] = (string) base64_decode($existingData[$k]);
            }
        }

        Kubectl::fromPrefix($kubectl)->putSecret($ns, $secretName, $keys, $names->labels());

        if ($this->secretsBackendAvailable($kubectl)) {
            $clusterEnv = $env === 'local' ? 'dev' : $env;
            $this->pushClusterSecret($kubectl, 'SUPABASE_JWT_SECRET', $keys['jwt-secret'], $clusterEnv);
            $this->pushClusterSecret($kubectl, 'SUPABASE_ANON_KEY', $keys['anon-key'], $clusterEnv);
            $this->pushClusterSecret($kubectl, 'SUPABASE_SERVICE_ROLE_KEY', $keys['service-role-key'], $clusterEnv);
            $this->pushClusterSecret($kubectl, 'SUPABASE_DB_PASSWORD', $keys['db-password'], $clusterEnv);
            $this->pushClusterSecret($kubectl, 'SUPABASE_DASHBOARD_PASSWORD', $keys['dashboard-password'], $clusterEnv);
        }

        return $keys;
    }

    private function enableVectorExtension(string $kubectl, string $ns, string $dbStatefulSet): void
    {
        Process::run(
            "{$kubectl} exec -n {$ns} statefulset/{$dbStatefulSet} -- psql -U postgres -d postgres -c ".escapeshellarg('create extension if not exists vector with schema extensions;'),
        );
    }
}
