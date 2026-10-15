<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolHost;
use LaravelZero\Framework\Commands\Command;

class ToolAliasCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithSso, InteractsWithToolRegistry, LaraKubeOutput, ResolvesToolHost;

    protected $signature = 'tool:alias
        {tool : The tool to add or remove an Ingress domain alias for (e.g. mail, git, sso)}
        {--alias= : Additional domain alias to register (e.g. send.next.site)}
        {--remove : Remove the specified domain alias instead of adding it}
        {--domain= : The target instance\'s host, for a tool with more than one (e.g. --domain=blog.example.com). Omit for the default instance}
        {--context= : Target a specific kube-context}';

    protected $description = 'Add or remove a secondary domain alias for a deployed cluster tool';

    public function handle(): int
    {
        $this->renderHeader();

        $toolSlug = (string) $this->argument('tool');
        $tool = ClusterTool::tryFrom($toolSlug);
        if ($tool === null) {
            $this->laraKubeError("Unknown tool '{$toolSlug}'.");

            return 1;
        }

        $aliasDomain = $this->sanitizeDomainInput((string) ($this->option('alias') ?: ''));

        if ($aliasDomain === '') {
            $this->laraKubeError('A valid alias domain is required — pass --alias=send.example.com.');

            return 1;
        }

        $targetDomain = (string) ($this->option('domain') ?: '');

        $context = (string) ($this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();

        // Host identity wins: a --domain that matches an already-registered
        // entry targets THAT instance in place (registry is the source of
        // truth for which instance serves a host), never a derived slug.
        // Ambiguity-safe: refuses to guess when 2+ instances exist and no
        // --domain disambiguates, same as sso:grant/sso:revoke/sso:org-grant.
        //
        // $instance stays nullable all the way through the registry lookups
        // below — null means "no explicit preference," which is what lets
        // isToolRegistered()/getToolHost() correctly resolve a tool's sole
        // entry even if its stored value is a stale pre-migration one. Only
        // coercing this to '' for display/naming purposes (see
        // $namingInstance below) would break that fallback for every tool
        // not yet redeployed under the new naming.
        $instance = $this->resolveInstanceForTool($tool, $kubectl, $targetDomain);
        if ($instance === false) {
            return 1;
        }
        // Only disambiguate with a parenthetical when there's a real named
        // instance to distinguish — a tool's sole/default install doesn't
        // need "(Mail Server (Stalwart))" tacked onto its own label.
        $suffix = $instance !== null ? " ({$instance})" : '';

        if (! $this->isToolRegistered($kubectl, $tool, $instance)) {
            $this->laraKubeError("Tool '{$tool->value}'{$suffix} is not registered on this cluster.");

            return 1;
        }

        $primaryHost = $this->getToolHost($kubectl, $tool, $instance);
        if (! $primaryHost) {
            $this->laraKubeError("Could not resolve primary host for '{$tool->value}'.");

            return 1;
        }

        $isRemove = (bool) $this->option('remove');

        if ($isRemove) {
            $this->removeToolAliasHost($kubectl, $tool, $aliasDomain, $instance);
            $this->laraKubeInfo("Removed alias domain '{$aliasDomain}' from {$tool->getLabel()}{$suffix}.");
        } else {
            $this->addToolAliasHost($kubectl, $tool, $aliasDomain, $instance);
            $this->laraKubeInfo("Registered alias domain '{$aliasDomain}' for {$tool->getLabel()}{$suffix}.");
        }

        $aliasHosts = $this->getToolAliasHosts($kubectl, $tool, $instance);

        // reapplyToolIngress() needs a concrete resource-naming string, not a
        // nullable "no preference" — '' here means "this tool's one/default
        // install, unsuffixed," same convention as everywhere else.
        $namingInstance = $instance ?? '';

        // Re-render tool ingress with updated multi-host manifest
        $this->reapplyToolIngress($kubectl, $tool, $primaryHost, $aliasHosts, $namingInstance);

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Ingress updated for {$tool->getLabel()}. Traefik will issue/refresh ACME TLS certs automatically.");
        $this->line("  <fg=gray>Primary Host:</> <fg=blue>https://{$primaryHost}</>");
        if ($aliasHosts !== []) {
            $this->line('  <fg=gray>Alias Hosts:</>  <fg=cyan>'.implode(', ', array_map(fn ($h) => "https://{$h}", $aliasHosts)).'</>');
        }
        $this->newLine();

        return 0;
    }
}
