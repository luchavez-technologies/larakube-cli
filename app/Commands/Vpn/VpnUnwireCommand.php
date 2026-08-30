<?php

namespace App\Commands\Vpn;

use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;
use App\Traits\PicksRegisteredTool;
use App\Traits\RefusesUnshippedTools;
use App\Traits\ResolvesToolHost;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class VpnUnwireCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithVpn, LaraKubeOutput, PicksRegisteredTool, RefusesUnshippedTools, ResolvesToolHost;

    protected $signature = 'vpn:unwire
        {environment=local : Environment whose deployment to unwire}
        {--tool= : The tool whose VPN restriction should be lifted}
        {--domain= : The instance to target (e.g. --domain=blog.example.com). Omit for the default instance}
        {--context= : Target a specific kube-context}';

    protected $description = "Lift a tool's VPN-only ingress restriction";

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->vpnWireKubectl($context);

        $selection = $this->resolveTool($kubectl);
        if ($selection === null) {
            return 1;
        }
        [$tool, $pickedHost] = $selection;

        $target = $tool->vpnMiddlewareTarget();
        if ($target === null) {
            $this->laraKubeError("'{$tool->value}' doesn't have a --vpn-only ingress mode.");

            return 1;
        }

        // Same resolution order as vpn:wire, so the two sides target the same
        // instance: an explicit --domain wins, then whatever the picker chose.
        $domainOption = (string) ($this->option('domain') ?: '');
        $domain = match (true) {
            $domainOption !== '' => $this->sanitizeDomainInput($domainOption),
            $pickedHost !== null => $pickedHost,
            default => '',
        };

        if ($domain !== '' && ! $tool->supportsMultipleInstances()) {
            $this->laraKubeError("{$tool->getLabel()} does not support multiple instances — omit --domain to target its single installation.");

            return 1;
        }

        return $this->unwire($tool, $target, $kubectl, $env, $domain);
    }

    protected function unwire(ClusterTool $tool, array $target, string $kubectl, string $env, string $domain = ''): int
    {
        $reapplied = $this->call("{$tool->value}:init", array_filter([
            'environment' => $env,
            '--domain' => $domain !== '' ? $domain : null,
            '--no-interaction' => true,
            '--proxied' => $this->toolIngressIsProxied($kubectl, $tool) ? '1' : null,
        ]));

        if ($reapplied !== 0) {
            $this->laraKubeError("Could not re-apply {$tool->getLabel()}'s ingress — aborting before touching the Middleware. Run `larakube {$tool->value}:init {$env}` manually, then retry.");

            return 1;
        }

        // The Middleware is SHARED by every instance of this tool -- each
        // ingress hardcodes larakube-shared-{tool}-vpn-only, with no instance
        // suffix -- so deleting it while another instance still references it
        // leaves that instance pointing at a Middleware Traefik cannot resolve.
        // Its route then stops serving rather than becoming public, which is
        // the opposite of what unwiring is for. Same shape as sso:unwire only
        // tearing down the shared SSO proxy once no gated tool is left.
        $remaining = $this->instancesStillVpnOnly($kubectl, $tool);

        if ($remaining !== []) {
            $this->laraKubeInfo("✅ {$tool->getLabel()} is reachable from anywhere again (VPN-only lifted).");
            $this->newLine();
            $this->line('  <fg=gray>Keeping the shared Middleware — still in use by:</>');
            foreach ($remaining as $host) {
                $this->line("  <fg=gray>  • {$host}</>");
            }
            $this->newLine();

            $this->refreshVpnSplitDns($kubectl, $env);

            return 0;
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

    /** @return array{0: ClusterTool, 1: ?string}|null tool + the chosen instance's host */
    protected function resolveTool(string $kubectl): ?array
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

            return [$tool, null];
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Passing --tool is required when running in non-interactive mode.');

            return null;
        }

        // Gated on hosts that are ACTUALLY restricted. Offering an
        // unrestricted host is worse than a no-op here: unwiring still
        // re-applies an ingress and can remove a shared Middleware.
        $restricted = [];
        foreach ($this->vpnOnlyIngresses($kubectl) as $entry) {
            $restricted[$entry['tool']->value.'|'.$entry['host']] = true;
        }

        return $this->pickRegisteredTool(
            $kubectl,
            'Lift VPN-only restriction on which tool?',
            fn (ClusterTool $candidate): bool => $candidate->hasVpnWire(),
            fn (ClusterTool $candidate, string $host): bool => isset($restricted[$candidate->value.'|'.$host]),
            emptyMessage: 'No tools are currently restricted to VPN peers.',
        );
    }

    /**
     * Every ingress in the cluster currently carrying a vpn-only middleware.
     *
     * Read from live Ingresses rather than the registry alone, because the
     * annotation IS the record of what is restricted -- vpn:wire and vpn:unwire
     * both work by re-applying an ingress with or without it.
     *
     * @return list<array{tool: ClusterTool, host: string}>
     */
    protected function vpnOnlyIngresses(string $kubectl): array
    {
        $raw = Process::run("{$kubectl} get ingress -A -o json")->output();

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $found = [];

        foreach ($payload['items'] ?? [] as $ingress) {
            $middlewares = (string) ($ingress['metadata']['annotations']['traefik.ingress.kubernetes.io/router.middlewares'] ?? '');

            if (! str_contains($middlewares, '-vpn-only@kubernetescrd')) {
                continue;
            }

            foreach (ClusterTool::shippedCases() as $candidate) {
                if (! $candidate->hasVpnWire()) {
                    continue;
                }

                $target = $candidate->vpnMiddlewareTarget();

                if ($target === null || ! str_contains($middlewares, "-{$target['name']}@kubernetescrd")) {
                    continue;
                }

                foreach ($ingress['spec']['rules'] ?? [] as $rule) {
                    $host = (string) ($rule['host'] ?? '');

                    if ($host !== '') {
                        $found[] = ['tool' => $candidate, 'host' => $host];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Hosts that would STILL reference the shared Middleware after this unwire.
     *
     * @return list<string>
     */
    protected function instancesStillVpnOnly(string $kubectl, ClusterTool $tool): array
    {
        $hosts = [];

        foreach ($this->vpnOnlyIngresses($kubectl) as $entry) {
            if ($entry['tool'] === $tool && $entry['host'] !== '') {
                $hosts[] = $entry['host'];
            }
        }

        return array_values(array_unique($hosts));
    }

    protected function vpnWireKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }
}
