<?php

namespace App\Commands\N8n;

use App\Commands\Tool\AbstractToolInitCommand;
use App\Data\ToolInstance;
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

abstract class N8nInitCommand extends AbstractToolInitCommand
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithFlow, InteractsWithIngressProxy, InteractsWithPlex, InteractsWithVolumeSizing, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

    protected function runInit(): int
    {
        return $this->deployFlow();
    }

    protected function deployFlow(): int
    {
        $engine = 'n8n';
        $toolBrand = $this->tool()->brandName();
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = Kubectl::forContext($context)->prefix();
        [$host, $instance] = $this->resolveInstanceAwareHost(SharedClusterService::FLOW, $this->tool(), $env, $kubectl, $toolBrand);

        $names = ToolInstance::forHost($this->tool(), $host, $engine);
        $ns = $names->namespace();
        $noPlex = (bool) $this->option('no-plex');
        $vpnOnly = (bool) $this->option('vpn-only');
        $cluster = Kubectl::forContext(($context ?? '') !== '' ? $context : null);

        $other = $this->otherFlowEngineOnHost($cluster, $host, $engine);
        if ($other !== null) {
            $otherLabel = FlowTool::from($other->value)->tool()->getLabel();
            $this->laraKubeError("{$host} already runs {$otherLabel}. A host runs one engine: remove it first with `larakube {$other->value}:remove {$env} --domain={$host}`, or pick another --domain.");

            return 1;
        }

        if ($vpnOnly && ! $this->ensureVpnMiddleware($this->tool(), $kubectl, $names->instance, $names->engine)) {
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
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => $cluster->apply((string) json_encode([
            'apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => $ns],
        ]))->ok);

        $this->withSpin('Syncing secrets...', fn () => $cluster->putSecret($ns, $names->secret(), [
            'encryption-key' => $encryptionKey,
            'db-password' => $dbPassword,
        ], $names->labels()));

        $manifest = view("k8s.{$engine}.shared", [
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

        if (! $this->guardClusterCapacity($kubectl, $manifest)) {
            return 1;
        }

        if (! $this->kubectlStep("Applying {$toolBrand} manifests...", fn () => $cluster->apply($manifest))
            || ! $this->kubectlStep("Waiting for {$toolBrand}...", fn () => $cluster->rolloutStatus($ns, $names->deployment(), timeoutSeconds: 420))) {
            return 1;
        }

        $this->registerDeployedTool($this->tool(), $kubectl, $host, instance: $instance, extra: ['engine' => $engine]);

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ {$toolBrand} stack is live.");
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");

        $this->newLine();

        return 0;
    }

    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment($this->tool());
    }
}
