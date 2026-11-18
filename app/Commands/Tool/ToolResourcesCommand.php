<?php

namespace App\Commands\Tool;

use App\Data\ToolInstance;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\DiscoversToolResources;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesToolCustomEnv;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Lists every ConfigMap and Secret a Cluster Tool instance uses, with each
 * key's owner. Secret values are never printed here; `tool:secret` reveals
 * one at a time.
 */
class ToolResourcesCommand extends Command
{
    use DeploysClusterTool, DiscoversToolResources, EmitsJsonOutput, InteractsWithToolRegistry, LaraKubeOutput, ManagesToolCustomEnv;

    protected $signature = 'tool:resources
        {environment=local : Environment the tool is deployed in}
        {--tool= : The tool to read (e.g. n8n, matrix)}
        {--domain= : The host identifying which registered instance to target. Omit for the tool\'s sole instance}
        {--context= : Target a specific kube-context}
        {--json : Emit machine-readable JSON output}';

    protected $description = "List a Cluster Tool instance's ConfigMaps and Secrets, with who owns each key";

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        if ($json) {
            $this->enableJsonMode();
        } else {
            $this->renderHeader();
        }

        $context = $this->resolveToolContext((string) $this->argument('environment'), $this->option('context'));
        $kubectl = Kubectl::forContext($context);
        $names = $this->resolveResourceInstance($kubectl->prefix());

        if (! $names instanceof ToolInstance) {
            return $this->failOut($json, $names);
        }

        $resources = $this->toolResources($kubectl, $names);

        if ($json) {
            $this->jsonOutput([
                'success' => true,
                'instance' => $names->instance,
                'namespace' => $names->namespace(),
                'resources' => $resources,
            ]);

            return 0;
        }

        $rows = [];
        foreach ($resources as $resource) {
            foreach ($resource['keys'] as $key) {
                $rows[] = [
                    $resource['kind'].'/'.$resource['name'],
                    $key['key'],
                    $key['owner'],
                    $resource['kind'] === 'Secret' ? '(hidden: tool:secret)' : (string) ($key['value'] ?? ''),
                ];
            }
        }

        if ($rows === []) {
            $this->laraKubeInfo('This instance uses no ConfigMaps or Secrets.');

            return 0;
        }

        table(headers: ['Resource', 'Key', 'Owner', 'Value'], rows: $rows);

        return 0;
    }

    private function failOut(bool $json, string $message): int
    {
        if ($json) {
            $this->jsonOutput(['success' => false, 'error' => $message]);
        } else {
            $this->laraKubeError($message);
        }

        return 1;
    }
}
