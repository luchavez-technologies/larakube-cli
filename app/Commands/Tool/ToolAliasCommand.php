<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class ToolAliasCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithSso, InteractsWithToolRegistry, LaraKubeOutput;

    protected $signature = 'tool:alias
        {tool : The tool to add or remove an Ingress domain alias for (e.g. mail, git, sso)}
        {domain : Additional domain alias to register (e.g. send.next.site)}
        {--remove : Remove the specified domain alias instead of adding it}
        {--instance=main : Named instance identifier (default: main)}
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

        $aliasDomain = strtolower(trim((string) $this->argument('domain')));
        $aliasDomain = (string) preg_replace('#^[a-z]+://#', '', $aliasDomain);
        $aliasDomain = (string) preg_replace('#[/:].*$#', '', $aliasDomain);
        $aliasDomain = trim($aliasDomain, ". \t");

        if ($aliasDomain === '') {
            $this->laraKubeError('A valid alias domain is required.');

            return 1;
        }

        $instance = (string) ($this->option('instance') ?: 'main');
        $context = (string) ($this->option('context') ?: null);
        $kubectl = $this->ssoKubectl($context);

        if (! $this->isToolRegistered($kubectl, $tool, $instance)) {
            $this->laraKubeError("Tool '{$tool->value}' (instance: {$instance}) is not registered on this cluster.");

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
            $this->laraKubeInfo("Removed alias domain '{$aliasDomain}' from {$tool->getLabel()} ({$instance}).");
        } else {
            $this->addToolAliasHost($kubectl, $tool, $aliasDomain, $instance);
            $this->laraKubeInfo("Registered alias domain '{$aliasDomain}' for {$tool->getLabel()} ({$instance}).");
        }

        $aliasHosts = $this->getToolAliasHosts($kubectl, $tool, $instance);

        // Re-render tool ingress with updated multi-host manifest
        $this->reapplyToolIngress($kubectl, $tool, $primaryHost, $aliasHosts, $instance);

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Ingress updated for {$tool->getLabel()}. Traefik will issue/refresh ACME TLS certs automatically.");
        $this->line("  <fg=gray>Primary Host:</> <fg=blue>https://{$primaryHost}</>");
        if ($aliasHosts !== []) {
            $this->line('  <fg=gray>Alias Hosts:</>  <fg=cyan>'.implode(', ', array_map(fn ($h) => "https://{$h}", $aliasHosts)).'</>');
        }
        $this->newLine();

        return 0;
    }

    protected function reapplyToolIngress(string $kubectl, ClusterTool $tool, string $primaryHost, array $aliasHosts, string $instance): bool
    {
        $view = "k8s.{$tool->value}.ingress";
        if (! view()->exists($view)) {
            return false;
        }

        $service = $tool->service();
        $isLocal = str_ends_with($primaryHost, '.test') || str_ends_with($primaryHost, '.localhost');

        $ingressName = $instance === 'main' ? ($service?->value ?? $tool->value) : "{$tool->value}-{$instance}";

        $manifest = view($view, [
            'host' => $primaryHost,
            'aliasHosts' => $aliasHosts,
            'serviceName' => $tool->deploymentName(),
            'isLocal' => $isLocal,
            'vpnOnly' => str_contains(Process::run("{$kubectl} get ingress {$ingressName} -n {$tool->namespace()} -o jsonpath='{.metadata.annotations}' --ignore-not-found")->output(), 'vpn-only'),
            'proxied' => str_contains(Process::run("{$kubectl} get ingress {$ingressName} -n {$tool->namespace()} -o jsonpath='{.metadata.annotations}' --ignore-not-found")->output(), 'cloudflare-proxied'),
        ])->render();

        $tmp = sys_get_temp_dir()."/larakube-alias-{$tool->value}.yaml";
        file_put_contents($tmp, $manifest);
        $result = Process::run("{$kubectl} apply -f {$tmp}");
        @unlink($tmp);

        return $result->successful();
    }
}
