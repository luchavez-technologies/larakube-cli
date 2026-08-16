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
    /**
     * @param  bool  $deferRegistration  True for tools whose real instance is
     *                                   derived from the host AFTER this call returns (CRM, DATA —
     *                                   "pure host-derived, no main fallback", see ClusterTool::CRM's
     *                                   commit history and docs/decisions on DATA's host-as-identity fix).
     *                                   For those, registering a stub under the DEFAULT $instance ('main')
     *                                   here would diverge from the real instance the caller computes
     *                                   moments later via instanceSlugFromHost($host) — registerTool()'s
     *                                   exact-instance-match merge can't reconcile the two, so a second,
     *                                   duplicate registry row gets appended instead of updating the
     *                                   first (confirmed live 2026-08-14, Twenty CRM). Every other tool's
     *                                   instance genuinely IS 'main' end-to-end, so this stays false for
     *                                   them — skipping it would only lose the "host survives a Ctrl-C
     *                                   before deploy finishes" convenience, which does not apply to
     *                                   host-derived tools anyway (their derived instance isn't known
     *                                   until after this call regardless).
     */
    protected function resolveToolHost(
        SharedClusterService $service,
        ClusterTool $tool,
        string $env,
        ?string $kubectl = null,
        string $instance = 'main',
        ?string $labelOverride = null,
        bool $deferRegistration = false,
    ): string {
        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $this->hostFromDomainOption($service, $domain, $instance);
        }

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld(), $instance);
        }

        // The CLUSTER is the compass for shared tools. A tool is cluster
        // infrastructure, not a property of any one Laravel app — the same
        // cluster serves many projects, and the tool must be resolvable from a
        // machine that has never cloned any of them. So a host recorded by a
        // previous deploy wins over anything in a project file.
        if ($kubectl !== null && method_exists($this, 'getToolHost')) {
            $recorded = $this->getToolHost($kubectl, $tool, $instance);
            if ($recorded !== null && $recorded !== '') {
                return $recorded;
            }
        }

        $config = $this->resolveProjectConfig();

        if ($this->option('no-interaction')) {
            return $this->resolveNonInteractiveHost($service, $tool, $env, $config, $kubectl, $instance);
        }

        return $this->promptForCloudHost($service, $env, $config, $tool, $kubectl, $instance, $labelOverride, $deferRegistration);
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
    protected function hostFromDomainOption(SharedClusterService $service, string $domain, string $instance = 'main'): string
    {
        // Strip anything copied from a URL: scheme, path, port, surrounding dots.
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = (string) preg_replace('#[/:].*$#', '', $domain);
        $domain = trim($domain, ". \t");

        $prefix = $service->hostPrefix();
        if ($instance !== '' && $instance !== 'main') {
            $prefix = "{$prefix}-{$instance}";
        }

        // Already the full host for THIS service (+instance) — don't prefix it twice.
        if ($prefix !== '' && str_starts_with($domain, $prefix.'.')) {
            return $domain;
        }

        return $service->hostFor($domain, $instance);
    }

    /**
     * The alternative reading of --domain=, for commands where it means "this
     * literal host, and therefore which instance" (AbstractToolRemoveCommand,
     * AbstractToolShowCommand, ToolAliasCommand, DataInitCommand, NotesInitCommand)
     * rather than hostFromDomainOption()'s "base domain, guess the prefix".
     * Deliberately does NOT auto-prefix — a value already meaning a specific
     * instance's host would get silently mangled by hostFor()'s prefixing
     * logic (which always assumes the bare-prefix/'main' case), which is
     * exactly what made per-instance targeting impossible before this existed.
     * Same URL-paste cleanup as hostFromDomainOption() (scheme/path/port/dots).
     */
    protected function sanitizeDomainInput(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = (string) preg_replace('#[/:].*$#', '', $domain);

        return trim($domain, ". \t");
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
        string $instance = 'main',
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
            return $config->getSharedServiceHost($service, $env, $instance);
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
        string $instance = 'main',
        ?string $labelOverride = null,
        bool $deferRegistration = false,
    ): string {
        if ($config === null) {
            $config = $this->resolveProjectConfig();
        }

        // The project-pinned host is main-only — .larakube.json has no
        // per-instance dimension, so reusing it for a named instance would
        // just recreate the exact host collision this parameter exists to
        // avoid.
        $existing = $instance === 'main' ? ($config?->getEnvironment($env)?->hosts[$service->value] ?? null) : null;
        if ($existing) {
            return $existing;
        }

        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        $default = ($config && $webHost) ? $config->getSharedServiceHost($service, $env, $instance) : '';

        $label = $labelOverride ?? $service->label();

        $host = text(
            label: "What host should {$label} use in '{$env}'?",
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
        //
        // $deferRegistration skips this for host-derived tools (see this
        // method's docblock) — their caller's own final registerDeployedTool()
        // call, made once the real instance is known, is the only write.
        if (! $deferRegistration && $tool !== null && $kubectl !== null && method_exists($this, 'registerTool')) {
            $this->registerTool($kubectl, $tool, ['host' => $host], $instance);
            $this->laraKubeInfo("Recorded the {$service->label()} host on the cluster.");
        }

        return $host;
    }
}
