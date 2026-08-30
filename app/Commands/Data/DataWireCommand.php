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
        $envVars = [
            "VITE_{$engineKey}_URL" => $dataUrl,
            "PUBLIC_{$engineKey}_URL" => $dataUrl,
            "NEXT_PUBLIC_{$engineKey}_URL" => $dataUrl,
            "ASTRO_{$engineKey}_URL" => $dataUrl,
        ];

        $envFileName = $env === 'local' ? '.env' : ".env.{$env}";
        $envFilePath = "{$projectPath}/{$envFileName}";

        if (! file_exists($envFilePath) && file_exists("{$projectPath}/.env")) {
            $envFilePath = "{$projectPath}/.env";
        }

        $this->syncEnvFile($projectPath, $envVars, false, $env);

        $engineLabel = $engine === 'pocketbase' ? 'PocketBase' : 'Directus';

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Wired {$engineLabel} API URL into ".basename($envFilePath));
        $this->newLine();
        $this->line("  <fg=gray>API URL:</>  <fg=blue>{$dataUrl}</>");
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
