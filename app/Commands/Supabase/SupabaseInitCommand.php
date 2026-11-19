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

        $s3Bucket = 'supabase';
        $s3Endpoint = '';
        $s3Region = 'us-east-1';
        $s3Secrets = [];

        if (! $noPlex) {
            // Without real Commons credentials Storage would start and then fail on every upload.
            $s3Creds = $this->readCommonsS3Credentials();
            if (! is_array($s3Creds) || ($s3Creds['access'] ?? '') === '' || ($s3Creds['secret'] ?? '') === '') {
                $this->laraKubeError('Could not read the Plex Commons S3 credentials. Run plex:status to check the Commons, or install with --no-plex to keep files on a volume.');

                return 1;
            }

            $s3Bucket = $names->bucket();
            $this->allocateStorageBucket(StorageDriver::SEAWEEDFS, $s3Bucket);
            $s3Endpoint = (string) ($s3Creds['endpoint'] ?? "http://seaweedfs.{$this->plexNamespace()}.svc.cluster.local:8333");
            $s3Region = (string) ($s3Creds['region'] ?? 'us-east-1');
            $s3Secrets = ['s3-access-key' => (string) $s3Creds['access'], 's3-secret-key' => (string) $s3Creds['secret']];
        }

        $keys = $this->resolveIdempotentKeys($kubectl, $ns, $names, $env, $s3Secrets);

        $vars = [
            'instance' => $instance,
            'host' => $host,
            'isLocal' => $isLocal,
            'vpnOnly' => $vpnOnly,
            'proxied' => $proxied,
            'noPlex' => $noPlex,
            's3Bucket' => $s3Bucket,
            's3Endpoint' => $s3Endpoint,
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

        $this->removeDisabledComponents($kubectl, $ns, $names, [
            'realtime' => $withRealtime,
            'functions' => $withFunctions,
            'imgproxy' => $withImgproxy,
            'supavisor' => $withPooler,
        ]);

        if (! $this->waitForStack($kubectl, $ns, $names, [
            'realtime' => $withRealtime,
            'functions' => $withFunctions,
            'imgproxy' => $withImgproxy,
            'supavisor' => $withPooler,
        ])) {
            return 1;
        }

        if (! $this->enableVectorExtension($kubectl, $ns, $names->name('db'))) {
            return 1;
        }

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

    /**
     * @param  array<string, string>  $extra  values stored alongside the keys but never generated (the Commons S3 login)
     * @return array<string, string>
     */
    private function resolveIdempotentKeys(string $kubectl, string $ns, ToolInstance $names, string $env, array $extra = []): array
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

        // The API keys are JWTs signed with the JWT secret: unless all three were kept, re-sign the keys
        // with the secret now in use, so a partial Secret never pairs one secret with another's keys.
        if (! isset($existingData['jwt-secret'], $existingData['anon-key'], $existingData['service-role-key'])) {
            $keys = [...$keys, ...SupabaseKeys::apiKeys($keys['jwt-secret'])];
        }

        Kubectl::fromPrefix($kubectl)->putSecret($ns, $secretName, [...$keys, ...$extra], $names->labels());

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

    /**
     * Turning an optional part off on a re-run removes it: apply alone never
     * deletes what the manifest stopped mentioning.
     *
     * @param  array<string, bool>  $enabled  component => whether it stays
     */
    private function removeDisabledComponents(string $kubectl, string $ns, ToolInstance $names, array $enabled): void
    {
        // The functions volume stays: it holds the user's own functions.
        $configMaps = ['functions' => 'functions-main', 'supavisor' => 'pooler-config'];

        foreach ($enabled as $component => $keep) {
            if (! $keep) {
                $name = $names->name($component);
                $refs = "deployment/{$name} service/{$name}".(isset($configMaps[$component]) ? " configmap/{$names->name($configMaps[$component])}" : '');
                Process::run("{$kubectl} delete {$refs} -n {$ns} --ignore-not-found");
            }
        }
    }

    /**
     * The gateway answering is not the stack being up: the database has to be
     * ready before pgvector can be enabled, and a crash-looping Auth or
     * Storage must not end in "Supabase is ready".
     *
     * @param  array<string, bool>  $optional  optional component => whether it is installed
     */
    private function waitForStack(string $kubectl, string $ns, ToolInstance $names, array $optional): bool
    {
        $workloads = ["statefulset/{$names->name('db')}"];

        foreach (['auth', 'rest', 'meta', 'storage', 'studio', ...array_keys(array_filter($optional))] as $component) {
            $workloads[] = "deployment/{$names->name($component)}";
        }

        foreach ($workloads as $workload) {
            $result = Process::timeout(330)->run("{$kubectl} rollout status {$workload} -n {$ns} --timeout=300s");

            if (! $result->successful()) {
                $this->laraKubeError("Supabase's {$workload} did not become ready. Check it with: kubectl describe {$workload} -n {$ns}");

                return false;
            }
        }

        return true;
    }

    private function enableVectorExtension(string $kubectl, string $ns, string $dbStatefulSet): bool
    {
        $result = Process::run(
            // supabase_admin over TCP, with the container's own PGPASSWORD: upstream's superuser login (postgres-meta
            // uses it too), where a local-socket login depends on the image's peer mapping.
            "{$kubectl} exec -n {$ns} statefulset/{$dbStatefulSet} -- psql -h localhost -U supabase_admin -d postgres -c ".escapeshellarg('create extension if not exists vector with schema extensions;'),
        );

        if (! $result->successful()) {
            $this->laraKubeError('Could not enable the pgvector extension: '.trim($result->errorOutput() ?: $result->output()));

            return false;
        }

        return true;
    }
}
