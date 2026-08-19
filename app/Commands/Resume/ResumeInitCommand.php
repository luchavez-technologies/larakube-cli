<?php

namespace App\Commands\Resume;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithResume;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class ResumeInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithResume, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets, VerifiesKubernetesRollout;

    protected $signature = 'resume:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Resume (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Reactive Resume self-hosted resume builder into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployResume();
    }

    protected function deployResume(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->resumeKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::RESUME, ClusterTool::RESUME, $env, $kubectl);

        $ns = $this->resumeNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::RESUME, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

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
        $s3Service ??= 'seaweedfs';
        if (! $this->ensureCommons([$s3Service])) {
            return 1;
        }

        $s3Creds = $this->readCommonsS3Credentials();
        if ($s3Creds === null) {
            $this->laraKubeError('Commons S3 credentials not found. Re-run `larakube plex:init`.');

            return 1;
        }
        $s3Driver = StorageDriver::from($s3Service);
        $s3Bucket = 'reactive-resume-storage';
        if (! $this->allocateStorageBucket($s3Driver, $s3Bucket)) {
            return 1;
        }

        $s3Endpoint = $this->resolveCommonsS3Endpoints($s3Driver, 'Reactive Resume')['public'];

        $dbPassword = $this->readResumeSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $authSecret = $this->readResumeSecret($kubectl, $ns, 'auth-secret') ?? Str::random(32);

        $dbName = 'reactiveresume';
        // Once OpenBao's database secrets engine already owns this static
        // role, defer to ITS current password instead of re-affirming a
        // locally-cached one that may predate OpenBao's own rotation — see
        // resolveManagedDbPassword()'s docblock.
        $dbPassword = $this->resolveManagedDbPassword($kubectl, $dbName, $dbPassword);

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $clusterEnv = $env === 'local' ? 'dev' : $env;
        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbName, $dbPassword, $authSecret, $clusterEnv): void {
            $cmd = "{$kubectl} create secret generic resume-reactive-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=auth-secret='.escapeshellarg($authSecret).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);

            if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
                if ($this->databaseEngineMounted($kubectl)) {
                    $this->registerStaticRole($kubectl, $dbName);

                    $realPassword = $this->readStaticRolePassword($kubectl, $dbName);
                    if ($realPassword !== null) {
                        Process::run(
                            "{$kubectl} patch secret resume-reactive-secrets -n {$ns} --type=json "
                            .'-p=\'[{"op":"replace","path":"/data/db-password","value":"'.base64_encode($realPassword).'"}]\'',
                        );
                    }
                } else {
                    $this->pushClusterSecret($kubectl, 'RESUME_DB_PASSWORD', $dbPassword, $clusterEnv);
                }
            }
        });

        $manifest = view('k8s.resume.shared', [
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
            's3Endpoint' => $s3Endpoint,
            's3Bucket' => $s3Bucket,
            's3AccessKey' => $s3Creds['access'],
            's3SecretKey' => $s3Creds['secret'],
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-resume-reactive.yaml');
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Reactive Resume manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, 'resume-reactive', 180),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::RESUME, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Reactive Resume is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Database:</>                <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::RESUME);
    }
}
