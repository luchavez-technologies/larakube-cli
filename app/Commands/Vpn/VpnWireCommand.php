<?php

namespace App\Commands\Vpn;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class VpnWireCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, LaraKubeOutput;

    protected $signature = 'vpn:wire
        {environment=local : Environment whose deployment to wire}
        {--tool= : The tool to restrict to VPN-only access}
        {--context= : Target a specific kube-context}
        {--remove   : Lift the VPN-only restriction instead of applying it}';

    protected $description = "Restrict a tool's ingress to NetBird VPN peers only — creates the Traefik Middleware --vpn-only's annotation already references";

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

        return $this->option('remove')
            ? $this->unwire($tool, $target, $kubectl, $env)
            : $this->wire($tool, $kubectl, $env);
    }

    protected function wire(ClusterTool $tool, string $kubectl, string $env): int
    {
        if (! $this->ensureVpnMiddleware($tool, $kubectl)) {
            $this->laraKubeError('Failed to create the Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        // Re-apply the tool's own ingress WITH the vpn-only annotation now
        // that the Middleware it references actually exists. Reuses the
        // tool's own *:init instead of duplicating its ingress-render logic.
        $reapplied = $this->call("{$tool->value}:init", array_filter([
            'environment' => $env,
            '--vpn-only' => true,
            '--no-interaction' => true,
        ]));

        if ($reapplied !== 0) {
            $this->laraKubeError("Middleware created, but re-applying {$tool->getLabel()}'s ingress failed — run `larakube {$tool->value}:init {$env} --vpn-only` manually.");

            return 1;
        }

        $this->laraKubeInfo("✅ {$tool->getLabel()} is now restricted to NetBird VPN peers only.");

        return 0;
    }

    protected function unwire(ClusterTool $tool, array $target, string $kubectl, string $env): int
    {
        // Re-apply the ingress WITHOUT the annotation FIRST — deleting the
        // Middleware before clearing the reference would leave a dangling
        // annotation and break the ingress for everyone, the exact failure
        // this command exists to prevent.
        $reapplied = $this->call("{$tool->value}:init", array_filter([
            'environment' => $env,
            '--no-interaction' => true,
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

        $this->laraKubeInfo("✅ {$tool->getLabel()} is reachable from anywhere again (VPN-only lifted).");

        return 0;
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

            return $tool;
        }

        $capable = array_values(array_filter(ClusterTool::cases(), fn (ClusterTool $t) => $t->vpnMiddlewareTarget() !== null));

        $options = [];
        foreach ($capable as $t) {
            $options[$t->value] = $t->getLabel();
        }

        return ClusterTool::from(select(
            label: $this->option('remove') ? 'Lift VPN-only restriction on which tool?' : 'Restrict which tool to VPN-only?',
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
