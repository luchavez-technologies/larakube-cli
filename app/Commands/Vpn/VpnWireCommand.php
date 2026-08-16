<?php

namespace App\Commands\Vpn;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\ResolvesToolHost;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class VpnWireCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, LaraKubeOutput, RefusesUnshippedTools, ResolvesToolHost;

    protected $signature = 'vpn:wire
        {environment=local : Environment whose deployment to wire}
        {--tool= : The tool to restrict to NetBird VPN peers only}
        {--domain= : The instance to target (e.g. --domain=blog.example.com). Omit for the default instance}
        {--context= : Target a specific kube-context}';

    protected $description = "Restrict a tool's ingress to NetBird VPN peers only — creates the Traefik Middleware --vpn-only's annotation already references";

    public function handle(): int
    {
        $this->renderHeader();

        $tool = $this->resolveTool();
        if ($tool === null) {
            return 1;
        }

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->vpnWireKubectl($context);

        $domain = (string) ($this->option('domain') ?: '');
        // Host identity wins: a --domain matching an already-registered entry
        // targets THAT instance in place (registry = source of truth).
        $instance = $this->resolveInstanceForDomain($kubectl, $tool, $domain);

        if ($domain !== '' && ! $tool->supportsMultipleInstances()) {
            $this->laraKubeError("{$tool->getLabel()} does not support multiple instances — omit --domain to target its single installation.");

            return 1;
        }

        $target = $tool->vpnMiddlewareTarget();
        if ($target === null) {
            $this->laraKubeError("'{$tool->value}' doesn't have a --vpn-only ingress mode.");

            return 1;
        }

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        return $this->wire($tool, $kubectl, $env, $domain);
    }

    protected function wire(ClusterTool $tool, string $kubectl, string $env, string $domain = ''): int
    {
        if (! $this->ensureVpnMiddleware($tool, $kubectl)) {
            $this->laraKubeError('Failed to create the Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Re-apply the tool's own ingress WITH the vpn-only annotation now
        // that the Middleware it references actually exists. Reuses the
        // tool's own *:init instead of duplicating its ingress-render logic.
        // --domain is passed through so a non-default instance's ingress is
        // the one re-applied, not always the tool's main installation.
        $reapplied = $this->call("{$tool->value}:init", array_filter([
            'environment' => $env,
            '--domain' => $domain !== '' ? $domain : null,
            '--vpn-only' => true,
            '--no-interaction' => true,
            '--proxied' => $this->toolIngressIsProxied($kubectl, $tool) ? '1' : null,
        ]));

        if ($reapplied !== 0) {
            $this->laraKubeError("Middleware created, but re-applying {$tool->getLabel()}'s ingress failed — run `larakube {$tool->value}:init {$env} --vpn-only` manually.");

            return 1;
        }

        $this->laraKubeInfo("✅ {$tool->getLabel()} is now restricted to NetBird VPN peers only.");

        return 0;
    }

    /** Preserve the Cloudflare proxy mode when re-applying a tool's Ingress. */
    protected function toolIngressIsProxied(string $kubectl, ClusterTool $tool): bool
    {
        return str_contains(Process::run(
            "{$kubectl} get ingress {$tool->value} -n {$tool->namespace()} "
            ."-o jsonpath='{.metadata.annotations}' --ignore-not-found",
        )->output(), 'cloudflare-proxied');
    }

    protected function resolveTool(): ?ClusterTool
    {
        $slug = (string) ($this->option('tool') ?: '');
        if ($slug !== '') {
            $tool = ClusterTool::tryFrom($slug);
            if ($tool === null) {
                $this->laraKubeError("Unknown tool '{$slug}'.");

                return null;
            }
            if ($this->refuseUnshippedTool($tool)) {
                return null;
            }

            return $tool;
        }

        $capable = array_values(array_filter(ClusterTool::shippedCases(), fn (ClusterTool $t) => $t->vpnMiddlewareTarget() !== null));

        $options = [];
        foreach ($capable as $t) {
            $options[$t->value] = $t->getLabel();
        }

        return ClusterTool::from(select(
            label: 'Restrict which tool to VPN-only?',
            options: $options,
            scroll: count($options),
        ));
    }

    /** Build the kubectl command, optionally scoped to a context, pinned to ~/.kube/config. */
    protected function vpnWireKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }
}
