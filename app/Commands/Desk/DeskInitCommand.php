<?php

namespace App\Commands\Desk;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithDesk;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class DeskInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithDesk, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

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
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->deskKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::DESK, ClusterTool::DESK, $env, $kubectl);

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
            if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, 'freescout', $dbPassword)) {
                return 1;
            }
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $dbPassword, $adminPassword, $adminEmail): void {
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

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-desk.yaml');
        file_put_contents($tmp, $manifest);

        // FreeScout runs its first-boot migrations on start, which can take a
        // while — give the rollout generous headroom.
        $rolledOut = $this->withSpin(
            'Applying FreeScout manifests (first boot runs migrations)...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, 'desk-freescout', 300),
        );
        $temporaryDirectory->delete();

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::DESK, $kubectl, $host, extra: ['adminEmail' => $adminEmail]);

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
        $default = 'admin@'.$this->deskDomain($host);

        return $this->flagOrPrompt(
            flag: 'admin-email',
            prompt: fn () => text(
                label: 'Admin email for the FreeScout account',
                default: $default,
                required: true,
            ),
            purpose: 'Admin email for FreeScout',
            example: "--admin-email={$default}",
        );
    }

    protected function deskDomain(string $host): string
    {
        $parts = explode('.', $host);

        return count($parts) > 2 ? implode('.', array_slice($parts, 1)) : $host;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::DESK);
    }
}
