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

class VpnWireCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithVpn, LaraKubeOutput, PicksRegisteredTool, RefusesUnshippedTools, ResolvesToolHost;

    protected $signature = 'vpn:wire
        {environment=local : Environment whose deployment to wire}
        {--tool= : The tool to restrict to NetBird VPN peers only}
        {--domain= : The instance to target (e.g. --domain=blog.example.com). Omit for the default instance}
        {--context= : Target a specific kube-context}';

    protected $description = "Restrict a tool's ingress to NetBird VPN peers only — creates the Traefik Middleware --vpn-only's annotation already references";

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->vpnWireKubectl($context);

        // kubectl first: the picker reads the cluster registry, so it cannot
        // run before there is a cluster to read.
        $selection = $this->resolveTool($kubectl);
        if ($selection === null) {
            return 1;
        }
        [$tool, $pickedHost] = $selection;

        // Host identity wins: an explicit --domain matching an already-registered
        // entry targets THAT instance in place (registry = source of truth).
        // Otherwise the instance the picker resolved is the one to act on, so
        // wire and unwire agree on what "this tool" means.
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

        if (! $tool->hasVpnWire()) {
            $this->laraKubeError("'{$tool->value}' doesn't have a --vpn-only ingress mode.");

            return 1;
        }

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

        // Restricting the ingress is only half of it: public DNS still points
        // this host at the cluster's public address, so a connected peer would
        // arrive from its ISP address and be refused by the allow-list. This
        // is what removes the need for an /etc/hosts entry.
        $this->refreshVpnSplitDns($kubectl, $env);

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

    /** @return array{0: ClusterTool, 1: ?string}|null tool + the chosen instance's host */
    protected function resolveTool(string $kubectl): ?array
    {
        $only = $this->namedTool();

        if ($only === false) {
            return null;
        }

        return $this->pickRegisteredTool(
            $kubectl,
            'Restrict which tool to VPN-only?',
            fn (ClusterTool $candidate): bool => $candidate->hasVpnWire(),
            emptyMessage: 'No VPN-capable tools are registered on this cluster.',
            only: $only,
            domain: (string) ($this->option('domain') ?: '') ?: null,
        );
    }

    /** Build the kubectl command, optionally scoped to a context, pinned to ~/.kube/config. */
    protected function vpnWireKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }
}
