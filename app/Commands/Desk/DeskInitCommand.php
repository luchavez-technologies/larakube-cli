<?php

namespace App\Commands\Desk;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithDesk;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class DeskInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithDesk, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'desk:init
        {environment?    : Environment this install targets — "local" (default) or cloud.}
        {--context=      : Target a specific kube-context}
        {--domain=       : Base domain OR full host for FreeScout (example.com → prefix.example.com)}
        {--engine=       : Help-desk engine to deploy ("freescout")}
        {--admin-email=  : Admin email for the FreeScout first-run account}
        {--no-plex       : Bypass Plex Commons and bundle a dedicated Postgres}
        {--vpn-only      : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the FreeScout help-desk / shared-inbox stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployDesk();
    }

    protected function deployDesk(): int
    {
        $this->resolveEngine();
        $env = $this->resolveEnvironment();
        $host = $this->resolveDeskHost($env);

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $this->plexContext = $context;
        $kubectl = $this->deskKubectl($context);
        $ns = $this->deskNamespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::DESK, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres'])) {
                return 1;
            }
        }

        $dbPassword = $this->readDeskSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
        $adminPassword = $this->readDeskSecret($kubectl, $ns, 'admin-password') ?? Str::random(16);
        $adminEmail = $this->readDeskSecret($kubectl, $ns, 'admin-email') ?? $this->resolveAdminEmail($host);

        if (! $noPlex) {
            if (! $this->allocateDatabase(\App\Enums\DatabaseDriver::POSTGRESQL, 'freescout', $dbPassword)) {
                return 1;
            }
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $adminPassword, $adminEmail) {
            Process::run(
                "{$kubectl} create secret generic desk-secrets -n {$ns} "
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                .'--from-literal=admin-password='.escapeshellarg($adminPassword).' '
                .'--from-literal=admin-email='.escapeshellarg($adminEmail).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $manifest = view('k8s.desk.freescout', [
            'host' => $host,
            'adminEmail' => $adminEmail,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-desk.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying FreeScout manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        // FreeScout runs its first-boot migrations on start, which can take a
        // while — give the rollout generous headroom.
        $this->withSpin('Waiting for FreeScout (first boot runs migrations)...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/desk-freescout -n {$ns} --timeout=300s",
            310,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ FreeScout help desk is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>   <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>Admin login:</>  <fg=blue>{$adminEmail}</> / <fg=blue>{$adminPassword}</>");
        $this->newLine();
        $this->line('  <fg=yellow>Connect a mailbox to Stalwart</> (Manage → Mailboxes → Connection Settings):');
        $this->line('     Outgoing (SMTP):  your Stalwart host, port 465 (SSL/TLS)');
        $this->line('     Incoming (IMAP):  your Stalwart host, port 993 (SSL/TLS)');
        $this->line('     Use a Stalwart account + application password.');
        $this->newLine();

        return 0;
    }

    protected function resolveEngine(): string
    {
        $explicit = strtolower((string) $this->option('engine'));
        if ($explicit !== '' && $explicit !== 'freescout') {
            $this->laraKubeError("Unknown desk engine '{$explicit}'. Supported: freescout.");
            exit(1);
        }

        return 'freescout';
    }

    protected function resolveAdminEmail(string $host): string
    {
        $explicit = (string) ($this->option('admin-email') ?? '');
        if ($explicit !== '') {
            return $explicit;
        }

        $default = 'admin@'.$this->deskDomain($host);

        if ($this->option('no-interaction')) {
            return $default;
        }

        return text(
            label: 'Admin email for the FreeScout account',
            default: $default,
            required: true,
        );
    }

    protected function deskDomain(string $host): string
    {
        $parts = explode('.', $host);

        return count($parts) > 2 ? implode('.', array_slice($parts, 1)) : $host;
    }

    protected function resolveDeskHost(string $env): string
    {
        $service = SharedClusterService::DESK;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        if ($env === 'local') {
            return (string) $this->resolveDeskHostReadOnly('local', null);
        }

        return $this->promptForCloudDeskHost($service, $env);
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::DESK);
    }

    protected function promptForCloudDeskHost(SharedClusterService $service, string $env): string
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
            placeholder: $default !== '' ? $default : 'e.g. desk.example.com',
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
