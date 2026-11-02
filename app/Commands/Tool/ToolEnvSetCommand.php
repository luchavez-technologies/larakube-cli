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
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Adds, updates, or removes a Cluster Tool instance's CUSTOM environment
 * variables — vars a person adds themselves (an external mail/DB service's
 * connection details, a feature-flag boolean, …), layered on top of a tool
 * via its own ConfigMap+Secret, never touching the keys `:init`/`:wire`
 * already manage. See ManagesToolCustomEnv for the storage/envFrom model.
 */
class ToolEnvSetCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, InteractsWithToolRegistry, LaraKubeOutput, ManagesToolCustomEnv, RequiresFlagsWhenNonInteractive;

    protected $signature = 'tool:env:set
        {environment=local : Environment the tool is deployed in}
        {--tool= : The tool to update (e.g. n8n, pocketbase, wordpress)}
        {--domain= : The host identifying which registered instance to target. Omit for the tool\'s sole instance}
        {--context= : Target a specific kube-context}
        {--set=* : A plain KEY=VALUE var to add or update}
        {--set-secret=* : A secret KEY=VALUE var to add or update}
        {--unset=* : A KEY to remove, from whichever side it currently lives on}
        {--restart : Roll out the Deployment afterward so the pod picks up the change}
        {--json : Emit machine-readable JSON output}';

    protected $description = "Add, update, or remove a Cluster Tool instance's custom environment variables";

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

        $toolSlug = $this->flagOrPrompt(
            'tool',
            fn () => text(label: 'Which tool should this update?', required: true),
            'the tool to update',
            '--tool=n8n',
        );
        $tool = ClusterTool::tryFrom($toolSlug);
        if ($tool === null) {
            return $this->failOut($json, "Unknown tool '{$toolSlug}'.");
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

        [$set, $setErrors] = $this->parseEnvPairs((array) $this->option('set'));
        [$setSecret, $setSecretErrors] = $this->parseEnvPairs((array) $this->option('set-secret'));
        $unset = array_values(array_unique(array_map('strval', (array) $this->option('unset'))));
        $errors = [...$setErrors, ...$setSecretErrors];

        if ($errors !== []) {
            return $this->failOut($json, 'Malformed KEY=VALUE pair(s): '.implode(', ', $errors));
        }

        $overlapping = array_intersect(array_keys($set), array_keys($setSecret));
        if ($overlapping !== []) {
            return $this->failOut($json, 'Key(s) passed to both --set and --set-secret: '.implode(', ', $overlapping));
        }

        if ($set === [] && $setSecret === [] && $unset === []) {
            return $this->failOut($json, 'Nothing to do — pass --set=, --set-secret=, and/or --unset=.');
        }

        $kc = Kubectl::fromPrefix($kubectl);
        $result = $this->applyCustomEnvChanges($kc, $names, $set, $setSecret, $unset);

        if (! $this->ensureCustomEnvWired($kc, $names, $result['configMap'], $result['secret'])) {
            return $this->failOut($json, "Updated the custom env values, but wiring them into {$tool->getLabel()}'s Deployment failed — re-run this command to retry just that step.");
        }

        $vaulted = false;
        if ($setSecret !== [] && $this->secretsBackendAvailable($kubectl)) {
            $vaulted = true;
            foreach ($setSecret as $key => $value) {
                $this->pushClusterSecret($kubectl, "CUSTOM_{$instance}_{$key}", $value, $env);
            }
        }

        $restarted = false;
        if ($this->option('restart')) {
            $restarted = $kc->rolloutRestart($names->namespace(), $names->deployment())->ok;
        }

        if ($json) {
            $this->jsonOutput([
                'success' => true,
                'instance' => $instance,
                'namespace' => $names->namespace(),
                'set' => array_keys($set),
                'setSecret' => array_keys($setSecret),
                'unset' => $unset,
                'vaulted' => $vaulted,
                'restarted' => $restarted,
            ]);

            return 0;
        }

        $this->laraKubeInfo("✅ Updated {$tool->getLabel()}'s custom environment variables.");
        if ($vaulted) {
            $this->line('  <fg=gray>Secret value(s) also mirrored into OpenBao for backup.</>');
        }
        if ($this->option('restart')) {
            $this->line($restarted
                ? '  <fg=gray>Deployment rolled out — the new values are live.</>'
                : '  <fg=yellow>⚠ Rollout restart failed — the values are saved but the running pod has not picked them up yet.</>');
        } elseif ($result['changed']) {
            $this->line("  <fg=gray>Saved. Pass --restart, or `larakube tool:env:set {$env} --tool={$tool->value} --restart`, to apply it to the running pod.</>");
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
