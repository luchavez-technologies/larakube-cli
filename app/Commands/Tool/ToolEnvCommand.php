<?php

namespace App\Commands\Tool;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesToolCustomEnv;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Reads a Cluster Tool instance's custom env overlay — the user-added vars
 * `tool:env:set` writes, never the keys a tool's own `:init`/`:wire` logic
 * manages (see ManagesToolCustomEnv's doc).
 */
class ToolEnvCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, InteractsWithToolRegistry, LaraKubeOutput, ManagesToolCustomEnv;

    protected $signature = 'tool:env
        {environment=local : Environment the tool is deployed in}
        {--tool= : The tool to read (e.g. n8n, pocketbase, wordpress)}
        {--domain= : The host identifying which registered instance to target. Omit for the tool\'s sole instance}
        {--context= : Target a specific kube-context}
        {--reveal : Print plaintext secret values (requires secret-read access on your context)}
        {--json : Emit machine-readable JSON output}';

    protected $description = "List a Cluster Tool instance's custom environment variables";

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        if ($json) {
            $this->enableJsonMode();
        } else {
            $this->renderHeader();
        }

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = Kubectl::forContext($context)->prefix();

        $toolSlug = (string) ($this->option('tool') ?? '');
        $tool = ClusterTool::tryFrom($toolSlug);
        if ($tool === null) {
            return $this->failOut($json, "Unknown tool '{$toolSlug}'. Pass --tool=<name>.");
        }

        $domainOption = (string) ($this->option('domain') ?: '');
        $instancePreference = $this->resolveInstanceForTool($tool, $kubectl, $domainOption);
        if ($instancePreference === false) {
            return 1;
        }

        $entry = $this->findToolInstanceEntry($kubectl, $tool, $instancePreference);
        if ($entry === null) {
            return $this->failOut($json, "'{$tool->value}' is not registered on this cluster.");
        }

        $instance = (string) ($entry['instance'] ?? '');
        $names = ToolInstance::forInstance($tool, $instance, (string) ($entry['engine'] ?? '') ?: null);

        $kc = Kubectl::fromPrefix($kubectl);
        $configMapName = $this->customEnvConfigMapName($names);
        $secretName = $this->customEnvSecretName($names);
        $configMap = $this->readCustomConfigMap($kc, $names->namespace(), $configMapName);
        $secret = $this->readCustomSecret($kc, $names->namespace(), $secretName);
        $vaulted = $this->secretsBackendAvailable($kubectl);

        $reveal = (bool) $this->option('reveal');
        $rows = [];
        foreach ($configMap ?? [] as $key => $value) {
            $rows[] = ['key' => $key, 'secret' => false, 'value' => $value];
        }
        foreach ($secret ?? [] as $key => $value) {
            $rows[] = ['key' => $key, 'secret' => true, 'value' => $reveal ? $value : str_repeat('•', 12)];
        }
        usort($rows, fn (array $a, array $b) => $a['key'] <=> $b['key']);

        if ($json) {
            $this->jsonOutput([
                'success' => true,
                'exists' => $configMap !== null || $secret !== null,
                'instance' => $instance,
                'namespace' => $names->namespace(),
                'vaulted' => $vaulted,
                'items' => $rows,
            ]);

            return 0;
        }

        if ($rows === []) {
            $this->laraKubeInfo("{$tool->getLabel()} has no custom environment variables yet. Set one with `larakube tool:env:set {$env} --tool={$tool->value} --set=KEY=value`.");

            return 0;
        }

        table(
            headers: ['Key', 'Type', 'Value'],
            rows: array_map(fn (array $row) => [
                $row['key'],
                $row['secret'] ? 'secret' : 'config',
                $row['value'],
            ], $rows),
        );

        if ($vaulted) {
            $this->line('  <fg=gray>Secret values are also mirrored into OpenBao for backup.</>');
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
