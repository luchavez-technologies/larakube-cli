<?php

namespace App\Commands\Data;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
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

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class DataUnwireCommand extends Command
{
    use DeploysClusterTool, GeneratesProjectInfrastructure, InteractsWithClusterContext, InteractsWithData, InteractsWithProjectConfig, LaraKubeOutput, PicksRegisteredTool, ResolvesToolEnvironment, ResolvesToolHost, SyncsClusterSecrets;

    protected $signature = 'data:unwire
        {environment=local : Environment whose Data engine host to unwire}
        {--engine=         : Data engine to target ("pocketbase", "directus", or "wordpress")}
        {--domain=         : Base domain OR full host for Data}
        {--context=        : Target a specific kube-context}
        {--force           : Skip confirmation prompt}';

    protected $description = 'Remove PocketBase, Directus, or WordPress API URL from the current project .env';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = Kubectl::forContext($context)->prefix();

        $picked = $this->pickRegisteredTool(
            kubectl: $kubectl,
            label: 'Data / Headless CMS',
            capable: fn (ClusterTool $tool) => in_array($tool, [ClusterTool::POCKETBASE, ClusterTool::DIRECTUS, ClusterTool::WORDPRESS], true),
            emptyMessage: "No Data / Headless CMS instance is registered for '{$env}'.",
            domain: $this->option('domain'),
        );

        if ($picked === null) {
            return 1;
        }

        [, $host] = $picked;

        if ($host === null) {
            $this->laraKubeError("No Data / Headless CMS host found for '{$env}'.");

            return 1;
        }

        $engine = $this->resolveEngine($kubectl, $host);
        $engineKey = strtoupper($engine);
        $engineLabel = match ($engine) {
            'pocketbase' => 'PocketBase',
            'wordpress' => 'WordPress',
            default => 'Directus',
        };

        $projectPath = getcwd();
        $envFileName = $env === 'local' ? '.env' : ".env.{$env}";
        $envFilePath = "{$projectPath}/{$envFileName}";

        if (! file_exists($envFilePath) && file_exists("{$projectPath}/.env")) {
            $envFilePath = "{$projectPath}/.env";
            $envFileName = '.env';
        }

        if (! file_exists($envFilePath)) {
            $this->laraKubeWarn("Environment file {$envFileName} does not exist in {$projectPath}.");

            return 0;
        }

        if ($config = $this->getProjectConfig($projectPath)) {
            if ($config->isLocked($envFileName)) {
                $this->laraKubeError("File {$envFileName} is locked in ".ConfigData::CONFIG_FILE.'.');

                return 1;
            }
        }

        if (! $this->option('force')) {
            $confirmed = confirm(
                label: "Remove {$engineLabel} API URL from {$envFileName}?",
                default: true,
            );

            if (! $confirmed) {
                $this->laraKubeWarn('Operation cancelled.');

                return 0;
            }
        }

        $framework = $this->getProjectConfig($projectPath)?->framework;
        $prefixes = $framework?->publicEnvPrefixes() ?? ['VITE_', 'PUBLIC_', 'NEXT_PUBLIC_', 'ASTRO_'];

        $targetKeys = ["{$engineKey}_URL"];
        foreach ($prefixes as $prefix) {
            $targetKeys[] = "{$prefix}{$engineKey}_URL";
        }

        $lines = explode("\n", (string) file_get_contents($envFilePath));
        $newLines = [];

        foreach ($lines as $line) {
            $matched = false;
            foreach ($targetKeys as $key) {
                if (preg_match('/^#?\\s*'.preg_quote($key, '/').'=.*/', $line)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $newLines[] = $line;
            }
        }

        file_put_contents($envFilePath, implode("\n", $newLines));

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Removed {$engineLabel} API URL variables from {$envFileName}");
        $this->newLine();

        return 0;
    }

    protected function resolveEngine(string $kubectl, string $host): string
    {
        $explicit = strtolower((string) $this->option('engine'));

        if (in_array($explicit, ['pocketbase', 'directus', 'wordpress'], true)) {
            return $explicit;
        }

        foreach ([ClusterTool::POCKETBASE, ClusterTool::DIRECTUS, ClusterTool::WORDPRESS] as $tool) {
            foreach ($this->getAllToolInstanceData($kubectl, $tool) as $instance) {
                if ($instance->host === $host && in_array($instance->engine, ['pocketbase', 'directus', 'wordpress'], true)) {
                    return $instance->engine;
                }
            }
        }

        return 'pocketbase';
    }
}
