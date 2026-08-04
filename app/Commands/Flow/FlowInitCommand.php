<?php

namespace App\Commands\Flow;

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithFlow;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class FlowInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithFlow, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'flow:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Flow (example.com → prefix.example.com)}
        {--no-plex   : Bypass Plex Commons and use local SQLite storage}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--engine=   : The automation engine to deploy ("n8n" or "windmill")}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy a workflow automation stack (n8n or Windmill) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployFlow();
    }

    protected function deployFlow(): int
    {
        $engine = $this->resolveEngine();
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->flowKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::FLOW, ClusterTool::FLOW, $env, $kubectl);

        $ns = $this->flowNamespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');

        // n8n and windmill are distinct products grouped under "flow" — they
        // coexist, not replace each other. If the other engine is already here,
        // just flag the scope overlap rather than blocking or overwriting.
        $other = $engine === 'windmill' ? 'n8n' : 'windmill';
        $otherInstalled = trim(Process::run(
            "{$kubectl} get deployment flow-{$other} -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';
        if ($otherInstalled && ! $this->option('force') && ! $this->cannotPrompt()
            && ! confirm("{$this->engineLabel($other)} is already installed under 'flow'. {$this->engineLabel($engine)} overlaps in scope — install it alongside?", default: true)) {
            $this->laraKubeInfo('Aborted — no changes made.');

            return 0;
        }

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::FLOW, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres'])) {
                return 1;
            }
        }

        $dbPassword = $this->readFlowDbPassword($kubectl, $ns) ?? Str::random(24);
        $encryptionKey = $this->readFlowEncryptionKey($kubectl, $ns) ?? Str::random(32);

        if (! $noPlex) {
            $driver = \App\Enums\DatabaseDriver::POSTGRESQL;

            if (! $this->allocateDatabase($driver, $engine, $dbPassword)) {
                return 1;
            }

            if ($engine === 'windmill') {
                $this->withSpin('Creating Windmill DB roles (windmill_user, windmill_admin) in the Commons...', function () use ($driver, $kubectl) {
                    $sql = implode("\n", [
                        "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'windmill_admin') THEN CREATE ROLE windmill_admin; END IF; END \$\$;",
                        "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'windmill_user') THEN CREATE ROLE windmill_user; END IF; END \$\$;",
                        'GRANT windmill_user TO windmill;',
                        'GRANT windmill_admin TO windmill;',
                    ]);
                    $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_windmill_roles');
                    file_put_contents($tmp, $sql);
                    Process::run(
                        $kubectl.' exec -i -n '.escapeshellarg($this->plexNamespace()).' deploy/'.$driver->value.' -- '
                        .'sh -c '.escapeshellarg($driver->commonsAdminClient()).' < '.escapeshellarg($tmp),
                    );
                    @unlink($tmp);
                });
            }
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $this->withSpin('Syncing secrets...', function () use ($kubectl, $ns, $encryptionKey, $dbPassword) {
            Process::run(
                "{$kubectl} create secret generic flow-secrets -n {$ns} "
                .'--from-literal=encryption-key='.escapeshellarg($encryptionKey).' '
                .'--from-literal=db-password='.escapeshellarg($dbPassword).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $manifest = view("k8s.flow.{$engine}", [
            'engine' => $engine,
            'host' => $host,
            'dbPassword' => $dbPassword,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-flow.yaml';
        file_put_contents($tmp, $manifest);

        $engineName = $engine === 'windmill' ? 'Windmill' : 'n8n';
        $deployName = $engine === 'windmill' ? 'deploy/flow-windmill' : 'deploy/flow-n8n';

        $this->withSpin("Applying Flow ({$engineName}) manifests...", fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->withSpin("Waiting for Flow ({$engineName})...", fn () => $this->runStreaming(
            "{$kubectl} rollout status {$deployName} -n {$ns} --timeout=120s",
            130,
        ));

        $this->registerDeployedTool(ClusterTool::FLOW, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Flow ({$engineName}) stack is live.");
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");

        $this->newLine();

        return 0;
    }

    protected function engineLabel(string $engine): string
    {
        return $engine === 'windmill' ? 'Windmill' : 'n8n';
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::FLOW);
    }

    protected function resolveEngine(): string
    {
        $explicit = strtolower((string) $this->option('engine'));
        if (in_array($explicit, ['n8n', 'windmill'], true)) {
            return $explicit;
        }

        return select(
            label: 'Which automation engine do you want to deploy?',
            options: [
                'windmill' => 'Windmill (Code-first, Rust/Python/Go, performant, free OIDC SSO)',
                'n8n' => 'n8n (Visual, Node.js-based, Zapier-like)',
            ],
            default: 'windmill',
        );
    }
}
