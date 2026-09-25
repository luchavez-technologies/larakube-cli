<?php

namespace App\Commands\Flow;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\FlowTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithFlow;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithVolumeSizing;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class FlowInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithFlow, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithVolumeSizing, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

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
        $kubectl = Kubectl::forContext($context)->prefix();
        $host = $this->resolveToolHost(SharedClusterService::FLOW, ClusterTool::FLOW, $env, $kubectl, '', $this->engineLabel($engine));

        $names = ToolInstance::forHost(ClusterTool::FLOW, $host, $engine);
        $ns = $names->namespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');
        $cluster = Kubectl::forContext(($context ?? '') !== '' ? $context : null);

        $other = $this->otherFlowEngineOnHost($cluster, $host, $engine);
        if ($other !== null) {
            $this->laraKubeError("{$host} already runs {$other->tool()->getLabel()}. A host runs one engine: remove it first with `larakube flow:remove {$env} --domain={$host}`, or pick another --domain.");

            return 1;
        }

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::FLOW, $kubectl, $names->instance, $names->engine)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres'])) {
                return 1;
            }
        }

        // Reused on every re-run: a new encryption key would make every
        // credential saved in n8n undecryptable.
        $dbPassword = $cluster->secretValue($ns, $names->secret(), 'db-password') ?? Str::random(24);
        $encryptionKey = $cluster->secretValue($ns, $names->secret(), 'encryption-key') ?? Str::random(32);

        $dbName = $names->database();

        if (! $noPlex) {
            $driver = DatabaseDriver::POSTGRESQL;

            if (! $this->allocateDatabase($driver, $dbName, $dbPassword)) {
                return 1;
            }

            if ($engine === 'windmill') {
                $this->withSpin('Creating Windmill DB roles (windmill_user, windmill_admin) in the Commons...', fn () => $cluster->exec(
                    $this->plexNamespace(),
                    'deploy/'.$driver->value,
                    ['sh', '-c', $driver->commonsAdminClient()],
                    stdin: implode("\n", [
                        "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'windmill_admin') THEN CREATE ROLE windmill_admin; END IF; END \$\$;",
                        "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'windmill_user') THEN CREATE ROLE windmill_user; END IF; END \$\$;",
                        // Windmill requires these two role names; they're shared by
                        // every instance, and granted to this instance's own role.
                        "GRANT windmill_user TO {$dbName};",
                        "GRANT windmill_admin TO {$dbName};",
                    ]),
                )->ok);
            }
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => $cluster->apply((string) json_encode([
            'apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => $ns],
        ]))->ok);

        $this->withSpin('Syncing secrets...', fn () => $cluster->putSecret($ns, $names->secret(), [
            'encryption-key' => $encryptionKey,
            'db-password' => $dbPassword,
        ], $names->labels()));

        $manifest = view("k8s.flow.{$engine}", [
            'volumeSize' => $this->volumeSizeResolver($kubectl, $ns),
            'engine' => $engine,
            'names' => $names,
            'host' => $host,
            'dbName' => $dbName,
            'dbPassword' => $dbPassword,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
        ])->render();

        $engineName = $this->engineLabel($engine);

        if (! $this->kubectlStep("Applying Flow ({$engineName}) manifests...", fn () => $cluster->apply($manifest))
            || ! $this->kubectlStep("Waiting for Flow ({$engineName})...", fn () => $cluster->rolloutStatus($ns, $names->deployment()))) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::FLOW, $kubectl, $host, extra: ['engine' => $engine]);

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Flow ({$engineName}) stack is live.");
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");

        $this->newLine();

        return 0;
    }

    protected function engineLabel(string $engine): string
    {
        return FlowTool::from($engine)->tool()->getLabel();
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
