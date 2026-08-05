<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;

use function Laravel\Prompts\text;

use RuntimeException;

/**
 * Resolve a tool's cluster hostname with a consistent fallback chain:
 *
 *   1. --domain flag              → derive via $service->hostFor($domain)
 *   2. env === 'local'            → .test TLD
 *   3. --no-interaction (tool:add):
 *      a. .larakube.json explicit → read-only lookup
 *      b. web host derivation    → conventional, no save
 *      c. cluster registry       → from previous deploy
 *      d. fail                   → clear error, run interactively
 *   4. Interactive               → prompt + persist to .larakube.json
 *
 * Host classes MUST either use DeploysClusterTool (which provides both
 * InteractsWithProjectConfig and InteractsWithToolRegistry) or provide
 * compatible getProjectConfig() / getToolHost() methods.
 */
trait ResolvesToolHost
{
    protected function resolveToolHost(
        SharedClusterService $service,
        ClusterTool $tool,
        string $env,
        ?string $kubectl = null,
    ): string {
        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $this->hostFromDomainOption($service, $domain);
        }

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        // The CLUSTER is the compass for shared tools. A tool is cluster
        // infrastructure, not a property of any one Laravel app — the same
        // cluster serves many projects, and the tool must be resolvable from a
        // machine that has never cloned any of them. So a host recorded by a
        // previous deploy wins over anything in a project file.
        if ($kubectl !== null && method_exists($this, 'getToolHost')) {
            $recorded = $this->getToolHost($kubectl, $tool);
            if ($recorded !== null && $recorded !== '') {
                return $recorded;
            }
        }

        $config = $this->resolveProjectConfig();

        if ($this->option('no-interaction')) {
            return $this->resolveNonInteractiveHost($service, $tool, $env, $config, $kubectl);
        }

        return $this->promptForCloudHost($service, $env, $config, $tool, $kubectl);
    }

    protected function resolveToolAliasHosts(string $kubectl, ClusterTool $tool, string $instance = 'main'): array
    {
        $explicit = (array) ($this->option('alias') ?? []);
        $recorded = method_exists($this, 'getToolAliasHosts') ? $this->getToolAliasHosts($kubectl, $tool, $instance) : [];

        $all = array_values(array_unique(array_filter(array_merge($recorded, $explicit))));

        if ($explicit !== [] && method_exists($this, 'addToolAliasHost')) {
            foreach ($explicit as $aliasHost) {
                $this->addToolAliasHost($kubectl, $tool, $aliasHost, $instance);
            }
        }

        return $all;
    }

    /**
     * Turn `--domain=` into this service's host.
     *
     * The flag means a BASE domain — `--domain=example.com` yields
     * `secrets.example.com` — and the old code blindly prefixed it. So
     * `--domain=secrets.luchtech.dev`, which reads like a perfectly sensible
     * thing to pass, silently produced `secrets.secrets.luchtech.dev` and
     * deployed an ingress for a hostname that resolves nowhere.
     *
     * Both readings are now accepted: a value already starting with this
     * service's own prefix is treated as the full host and used verbatim. A
     * pasted scheme, trailing slash or stray dots are tolerated too, because
     * every one of those is a thing an operator will paste from a browser bar.
     */
    protected function hostFromDomainOption(SharedClusterService $service, string $domain): string
    {
        // Strip anything copied from a URL: scheme, path, port, surrounding dots.
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = (string) preg_replace('#[/:].*$#', '', $domain);
        $domain = trim($domain, ". \t");

        $prefix = $service->hostPrefix();

        // Already the full host for THIS service — don't prefix it twice.
        if ($prefix !== '' && str_starts_with($domain, $prefix.'.')) {
            return $domain;
        }

        return $service->hostFor($domain);
    }

    protected function resolveProjectConfig(): ?ConfigData
    {
        $projectPath = getcwd();

        return file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;
    }

    protected function resolveNonInteractiveHost(
        SharedClusterService $service,
        ClusterTool $tool,
        string $env,
        ?ConfigData $config,
        ?string $kubectl = null,
    ): string {
        // The cluster registry is checked by the caller before we get here, so
        // a stored host has already won. What's left is derivation: a tool host
        // can be inferred from the environment's OWN web host (app.example.com
        // → flow.example.com). That reads the project's domain, which is the
        // project's business — it is not tool metadata, and nothing is written
        // back. Reading a *stored* tool host out of .larakube.json is gone:
        // tool state lives on the cluster, full stop.
        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        if ($config && $webHost) {
            return $config->getSharedServiceHost($service, $env);
        }

        throw new RuntimeException(
            "No '{$service->value}' host is recorded on the cluster for '{$env}'. "
            .'Pass --domain, or run interactively so the host can be captured.',
        );
    }

    protected function promptForCloudHost(
        SharedClusterService $service,
        string $env,
        ?ConfigData $config = null,
        ?ClusterTool $tool = null,
        ?string $kubectl = null,
    ): string {
        if ($config === null) {
            $config = $this->resolveProjectConfig();
        }

        $existing = $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
        if ($existing) {
            return $existing;
        }

        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        $default = ($config && $webHost) ? $config->getSharedServiceHost($service, $env) : '';

        $host = text(
            label: "What host should {$service->label()} use in '{$env}'?",
            placeholder: $default !== '' ? $default : "e.g. {$service->hostPrefix()}.example.com",
            default: $default,
            required: true,
        );

        // Persist to the CLUSTER registry, not .larakube.json. These tools have
        // no relationship to the Laravel app whose directory you happen to be
        // standing in — writing their hosts into a project blueprint pollutes it
        // with cluster state, makes the value invisible from anywhere else, and
        // means a second project on the same cluster re-prompts for a host that
        // is already known. registerTool() merges, so this is safe to call
        // before {tool}:init records the rest of its metadata.
        if ($tool !== null && $kubectl !== null && method_exists($this, 'registerTool')) {
            $this->registerTool($kubectl, $tool, ['host' => $host]);
            $this->laraKubeInfo("Recorded the {$service->label()} host on the cluster.");
        }

        return $host;
    }
}
