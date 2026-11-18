<?php

namespace App\Commands\Tool;

use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\DiscoversToolResources;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesToolCustomEnv;
use LaravelZero\Framework\Commands\Command;

/**
 * Reveals one key of one Secret a Cluster Tool instance uses. Only Secrets
 * `tool:resources` lists for that instance can be read, so this never
 * becomes a way to read an arbitrary Secret on the cluster.
 */
class ToolSecretCommand extends Command
{
    use DeploysClusterTool, DiscoversToolResources, EmitsJsonOutput, InteractsWithToolRegistry, LaraKubeOutput, ManagesToolCustomEnv;

    protected $signature = 'tool:secret
        {environment=local : Environment the tool is deployed in}
        {--tool= : The tool the Secret belongs to}
        {--domain= : The host identifying which registered instance to target. Omit for the tool\'s sole instance}
        {--name= : The Secret\'s name, as tool:resources lists it}
        {--key= : The key to reveal}
        {--context= : Target a specific kube-context}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Reveal one value of a Secret a Cluster Tool instance uses';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        if ($json) {
            $this->enableJsonMode();
        }

        $name = (string) ($this->option('name') ?? '');
        $key = (string) ($this->option('key') ?? '');
        if ($name === '' || $key === '') {
            return $this->failOut($json, 'Pass --name=<secret> and --key=<key>.');
        }

        $context = $this->resolveToolContext((string) $this->argument('environment'), $this->option('context'));
        $kubectl = Kubectl::forContext($context);
        $names = $this->resolveResourceInstance($kubectl->prefix());

        if (! $names instanceof ToolInstance) {
            return $this->failOut($json, $names);
        }

        $listed = array_filter(
            $this->toolResources($kubectl, $names),
            fn (array $resource): bool => $resource['kind'] === 'Secret' && $resource['name'] === $name
                && in_array($key, array_column($resource['keys'], 'key'), true),
        );

        if ($listed === []) {
            return $this->failOut($json, "'{$name}' has no key '{$key}' among the Secrets this instance uses.");
        }

        $secret = $kubectl->get(new ResourceRef('Secret', $name, $names->namespace()));
        $encoded = $secret['data'][$key] ?? null;
        $value = is_string($encoded) ? base64_decode($encoded, true) : false;

        if ($value === false) {
            return $this->failOut($json, "Could not read '{$key}' from '{$name}'.");
        }

        if ($json) {
            $this->jsonOutput(['success' => true, 'name' => $name, 'key' => $key, 'value' => $value]);
        } else {
            $this->line($value);
        }

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
