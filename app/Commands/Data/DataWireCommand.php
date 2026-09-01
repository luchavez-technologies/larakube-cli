<?php

namespace App\Commands\Data;

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\DeploysClusterTool;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithData;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\SyncsClusterSecrets;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class DataWireCommand extends Command
{
    use DeploysClusterTool, GeneratesProjectInfrastructure, InteractsWithClusterContext, InteractsWithData, InteractsWithProjectConfig, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, SyncsClusterSecrets;

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
        $engine = $this->resolveEngine();
        // No --instance: the host IS the identity (ADR 0012), and
        // resolveToolHost() already reads --domain itself. Carrying a separate
        // slug knob alongside it meant two flags could disagree about which
        // installation you meant.
        $host = $this->resolveToolHost(SharedClusterService::DATA, ClusterTool::DATA, $env, $kubectl);

        if ($host === null) {
            $this->laraKubeError("No Data / Headless CMS host found for '{$env}'. Run `larakube data:init {$env}` first.");

            return 1;
        }

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

    protected function resolveEngine(): string
    {
        $explicit = strtolower((string) $this->option('engine'));
        if (in_array($explicit, ['pocketbase', 'directus'], true)) {
            return $explicit;
        }

        if ($this->option('no-interaction')) {
            return 'pocketbase';
        }

        return select(
            label: 'Which Data engine would you like to wire to your .env?',
            options: [
                'pocketbase' => 'PocketBase (VITE_POCKETBASE_URL)',
                'directus' => 'Directus (VITE_DIRECTUS_URL)',
            ],
            default: 'pocketbase',
        );
    }
}
