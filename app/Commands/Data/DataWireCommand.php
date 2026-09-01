<?php

namespace App\Commands\Data;

use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithData;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\PicksRegisteredTool;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\SyncsClusterSecrets;
use LaravelZero\Framework\Commands\Command;

class DataWireCommand extends Command
{
    use DeploysClusterTool, GeneratesProjectInfrastructure, InteractsWithClusterContext, InteractsWithData, InteractsWithProjectConfig, LaraKubeOutput, PicksRegisteredTool, ResolvesToolEnvironment, ResolvesToolHost, SyncsClusterSecrets;

    protected $signature = 'data:wire
        {environment=local : Environment whose Data engine host to wire}
        {--engine=         : Data engine to target ("pocketbase" or "directus")}
        {--domain=         : Base domain OR full host for Data}
        {--context=        : Target a specific kube-context}';

    protected $description = 'Inject PocketBase or Directus API URL into the current project .env';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->dataKubectl($context);
        // Pick from what is REGISTERED, rather than prompting for a hostname.
        // This command points a project at an install that already exists, so
        // a free-typed host could only ever produce a URL to nothing — and the
        // old prompt asked for a host using the service label ("Directus")
        // regardless of the engine you had just chosen one question earlier.
        $picked = $this->pickRegisteredTool(
            kubectl: $kubectl,
            label: 'Data / Headless CMS',
            capable: fn (ClusterTool $tool) => $tool === ClusterTool::DATA,
            emptyMessage: "No Data / Headless CMS instance is registered for '{$env}'.",
            only: ClusterTool::DATA,
            domain: $this->option('domain'),
        );

        if ($picked === null) {
            return 1;
        }

        [, $host] = $picked;

        if ($host === null) {
            $this->laraKubeError("No Data / Headless CMS host found for '{$env}'. Run `larakube data:init {$env}` first.");

            return 1;
        }

        // The registry recorded which engine this instance runs, so the answer
        // comes WITH the instance instead of being asked separately — one
        // picker instead of two prompts that could contradict each other.
        $engine = $this->resolveEngine($kubectl, $host);

        $projectPath = getcwd();
        $dataUrl = "https://{$host}";

        $engineKey = strtoupper($engine);

        // Only emit what this framework's bundle can actually read. Writing all
        // four prefixes unconditionally left three dead variables in every
        // project. A directory with no blueprint is the one case where we
        // genuinely cannot tell, so it still gets all of them.
        $prefixes = $this->getProjectConfig($projectPath)?->framework?->publicEnvPrefixes()
            ?? ['VITE_', 'PUBLIC_', 'NEXT_PUBLIC_', 'ASTRO_'];

        $envVars = [];
        foreach ($prefixes as $prefix) {
            $envVars["{$prefix}{$engineKey}_URL"] = $dataUrl;
        }

        // Always the bare name too: a server-rendered framework reads the
        // environment directly and has no browser-exposure prefix, so without
        // this it would receive nothing at all.
        $envVars["{$engineKey}_URL"] = $dataUrl;

        $envFileName = $env === 'local' ? '.env' : ".env.{$env}";
        $envFilePath = "{$projectPath}/{$envFileName}";

        if (! file_exists($envFilePath) && file_exists("{$projectPath}/.env")) {
            $envFilePath = "{$projectPath}/.env";
        }

        // syncEnvFile() returns early when the local .env is absent, so on a
        // project that has never had one — a fresh SPA, which needs no .env
        // until it has a backend — this command used to print "wired" and write
        // nothing at all. Wiring a URL into .env IS the whole job here, so
        // creating the file is the correct move rather than a silent no-op.
        if (! file_exists($envFilePath)) {
            file_put_contents($envFilePath, '');
        }

        // Harden BEFORE writing. This command creates the file, so it owns the
        // question of whether the file is committable — scaffolding alone does
        // not cover a project adopted with `larakube init`, and by the time
        // anyone notices, it is already in someone's history.
        if ($config = $this->getProjectConfig($projectPath)) {
            $this->hardenGitIgnore($config);
        }

        $this->syncEnvFile($projectPath, $envVars, false, $env);

        // Report what actually landed, never an unverified success.
        if (! str_contains((string) file_get_contents($envFilePath), (string) array_key_first($envVars))) {
            $this->laraKubeError('Could not write to '.basename($envFilePath).' — is it locked in .larakube.json?');

            return 1;
        }

        $engineLabel = $engine === 'pocketbase' ? 'PocketBase' : 'Directus';

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Wired {$engineLabel} API URL into ".basename($envFilePath));
        $this->newLine();
        $this->line("  <fg=gray>API URL:</>  <fg=blue>{$dataUrl}</>");
        foreach (array_keys($envVars) as $key) {
            $this->line("  <fg=gray>Variable:</> <fg=blue>{$key}</>");
        }
        $this->newLine();

        return 0;
    }

    /**
     * An explicit --engine still wins, but otherwise the engine is whatever the
     * chosen instance actually runs — read from the registry rather than asked.
     * Asking produced a real contradiction: you could answer "PocketBase" and
     * then be prompted for the host "Directus" should use.
     */
    protected function resolveEngine(string $kubectl, string $host): string
    {
        $explicit = strtolower((string) $this->option('engine'));

        if (in_array($explicit, ['pocketbase', 'directus'], true)) {
            return $explicit;
        }

        foreach ($this->getAllToolInstanceData($kubectl, ClusterTool::DATA) as $instance) {
            if ($instance->host === $host && in_array($instance->engine, ['pocketbase', 'directus'], true)) {
                return $instance->engine;
            }
        }

        // A registry that predates engine tracking, or lags a fresh install.
        return 'pocketbase';
    }
}
