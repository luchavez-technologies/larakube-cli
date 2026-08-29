<?php

namespace App\Commands\Vpn;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class VpnUnwireCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithVpn, LaraKubeOutput, RefusesUnshippedTools;

    protected $signature = 'vpn:unwire
        {environment=local : Environment whose deployment to unwire}
        {--tool= : The tool whose VPN restriction should be lifted}
        {--context= : Target a specific kube-context}';

    protected $description = "Lift a tool's VPN-only ingress restriction";

    public function handle(): int
    {
        $this->renderHeader();

        $tool = $this->resolveTool();
        if ($tool === null) {
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
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->vpnWireKubectl($context);

        return $this->unwire($tool, $target, $kubectl, $env);
    }

    protected function unwire(ClusterTool $tool, array $target, string $kubectl, string $env): int
    {
        $reapplied = $this->call("{$tool->value}:init", array_filter([
            'environment' => $env,
            '--no-interaction' => true,
            '--proxied' => $this->toolIngressIsProxied($kubectl, $tool) ? '1' : null,
        ]));

        if ($reapplied !== 0) {
            $this->laraKubeError("Could not re-apply {$tool->getLabel()}'s ingress — aborting before touching the Middleware. Run `larakube {$tool->value}:init {$env}` manually, then retry.");

            return 1;
        }

        $ok = $this->removeResources(
            "Removing VPN-only Middleware for {$tool->getLabel()}...",
            "{$kubectl} delete middleware/{$target['name']} -n {$target['namespace']} --ignore-not-found",
        );

        if (! $ok) {
            $this->laraKubeError('Failed to remove the Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // The host is public again, so its split-DNS override has to go with
        // it -- otherwise peers keep resolving it to the gateway, which no
        // longer has any reason to be in the path.
        $this->refreshVpnSplitDns($kubectl, $env);

        $this->laraKubeInfo("✅ {$tool->getLabel()} is reachable from anywhere again (VPN-only lifted).");

        return 0;
    }

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
            label: 'Lift VPN-only restriction on which tool?',
            options: $options,
            scroll: count($options),
        ));
    }

    protected function vpnWireKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }
}
