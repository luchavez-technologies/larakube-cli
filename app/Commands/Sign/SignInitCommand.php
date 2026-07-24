<?php

namespace App\Commands\Sign;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSign;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class SignInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithPlex, InteractsWithSign, LaraKubeOutput, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'sign:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Sign (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Deploy the Documenso electronic signature stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySign();
    }

    protected function deploySign(): int
    {
        $env = $this->resolveEnvironment();
        $host = $this->resolveSignHost($env);

        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->signKubectl($context);
        $ns = $this->signNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::SIGN, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $this->ensureCommons(['postgres'])) {
            return 1;
        }

        $dbPassword = $this->readSignSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $nextauthSecret = $this->readSignSecret($kubectl, $ns, 'nextauth-secret') ?? bin2hex(random_bytes(32));

        $dbName = 'sign_documenso';

        if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $nextauthSecret) {
            $cmd = "{$kubectl} create secret generic sign-documenso-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=nextauth-secret='.escapeshellarg($nextauthSecret).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -";
            Process::run($cmd);
        });

        $manifest = view('k8s.sign.shared', [
            'host' => $host,
            'plexNamespace' => $this->plexNamespace(),
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-sign-documenso.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Documenso manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin('Waiting for Documenso...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/sign-documenso -n {$ns} --timeout=180s",
            190,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Documenso signature stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Database:</>    <fg=blue>Commons Postgres</> · DB <fg=blue>{$dbName}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveSignHost(string $env): string
    {
        $service = SharedClusterService::SIGN;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveSignHostReadOnly('local', null);
        }

        return $this->promptForCloudSignHost($service, $env);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::SIGN);
    }

    protected function promptForCloudSignHost(SharedClusterService $service, string $env): string
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
            placeholder: $default !== '' ? $default : 'e.g. sign.example.com',
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
