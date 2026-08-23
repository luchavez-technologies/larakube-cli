<?php

namespace App\Commands\Secrets;

use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEngine;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class SecretsRotateCommand extends Command
{
    use DeploysClusterTool, LaraKubeOutput, RefusesUnshippedTools, RequiresFlagsWhenNonInteractive, ResolvesToolEngine, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets;

    protected $signature = 'secrets:rotate
        {environment=local : Environment whose secrets to target}
        {--tool=     : The tool whose Commons DB password to rotate immediately}
        {--domain=   : The instance to target (e.g. --domain=blog.example.com). Omit for default instance}
        {--engine=   : Specific engine to target explicitly, skipping auto-detection}
        {--all       : Rotate DB passwords for every installed DB-rotatable tool}
        {--context=  : Target a specific kube-context}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Manually trigger an immediate OpenBao database password rotation for a tool';

    public function handle(): int
    {
        $this->renderHeader();

        $env = $this->resolveToolEnvironment(ClusterTool::SECRETS);
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->secretsKubectl($context);

        if (! $this->secretsBackendAvailable($kubectl)) {
            $this->laraKubeWarn('OpenBao is not deployed. Run `larakube secrets:init` first.');

            return 1;
        }

        if (! $this->databaseEngineMounted($kubectl)) {
            $this->laraKubeWarn('The OpenBao database secrets engine is not mounted yet.');
            $this->line('  <fg=gray>Run</> <fg=blue>larakube plex:init</> <fg=gray>to wire it.</>');

            return 1;
        }

        $domain = (string) ($this->option('domain') ?: '');

        $targets = $this->resolveTargets($kubectl, $domain);
        if ($targets === []) {
            return 1;
        }

        $label = count($targets) === 1 ? $targets[0][0]->getLabel() : count($targets).' tool(s)';
        if (! $this->option('force') && ! confirm(
            label: "Immediately rotate {$label}'s database password in OpenBao?",
            default: true,
        )) {
            return 0;
        }

        $ok = true;
        foreach ($targets as [$tool, $targetInstance, $engine]) {
            $ok = $this->rotateTool($kubectl, $tool, $targetInstance, $engine) && $ok;
        }

        return $ok ? 0 : 1;
    }

    /**
     * Resolve target tool(s) for rotation. $domain, like secrets:wire, only
     * ever targets ONE specific tool's instance and is ignored with --all —
     * the instance itself is resolved per-tool (not once up front against an
     * unrelated tool enum), matching secrets:wire's resolveTargets(). Passing
     * ClusterTool::SECRETS into resolveInstanceForDomain() here regardless of
     * which tool was actually being rotated was a real bug: it silently
     * resolved to whatever instance SECRETS itself maps that host to, then
     * appended it as a `-{instance}` suffix onto a DIFFERENT tool's secret
     * name via dbSecretRef() — reliably misresolving as "not installed" for
     * any tool whose host doesn't happen to match. Confirmed live 2026-08-23
     * on Mail/Stalwart, which really was installed.
     *
     * @return array<int, array{0: ClusterTool, 1: string, 2: string|null}>
     */
    protected function resolveTargets(string $kubectl, string $domain): array
    {
        $capable = array_filter(ClusterTool::shippedCases(), fn (ClusterTool $t) => $t->dbSecretRef() !== null);

        $resolve = function (ClusterTool $t, ?string $forInstance) use ($kubectl) {
            $inst = $forInstance ?? '';
            $engine = $t->engines() !== []
                ? $this->resolveInstanceEngine($kubectl, $t, $forInstance, (string) ($this->option('engine') ?: '') ?: null)
                : null;

            if ($t->dbSecretRef($inst, $engine) === null) {
                return null;
            }

            if (! $this->deploymentExists($kubectl, $t->namespace(), $t->deploymentName($inst, $engine))) {
                return null;
            }

            return [$t, $inst, $engine];
        };

        if ($this->option('all')) {
            $installed = [];
            foreach ($capable as $t) {
                $resolved = $resolve($t, null);
                if ($resolved !== null) {
                    $installed[] = $resolved;
                }
            }

            if ($installed === []) {
                $this->laraKubeWarn('No DB-rotatable tools are installed.');
            }

            return $installed;
        }

        $slug = $this->option('tool');
        if ($slug !== null) {
            $tool = ClusterTool::tryFrom($slug);
            if ($tool === null) {
                $this->laraKubeError("'{$slug}' does not have a Commons database password OpenBao can rotate.");

                return [];
            }

            if ($this->refuseUnshippedTool($tool)) {
                return [];
            }

            if ($tool->dbSecretRef() === null) {
                $this->laraKubeError("'{$slug}' does not have a Commons database password OpenBao can rotate.");

                return [];
            }

            $targetInst = $domain !== '' ? $this->resolveInstanceForDomain($kubectl, $tool, $domain) : null;
            $resolved = $resolve($tool, $targetInst);
            if ($resolved === null) {
                $this->laraKubeError("{$tool->getLabel()} is not installed at this instance.");

                return [];
            }

            return [$resolved];
        }

        $installed = [];
        foreach ($capable as $t) {
            $targetInst = $domain !== '' ? $this->resolveInstanceForDomain($kubectl, $t, $domain) : null;
            $resolved = $resolve($t, $targetInst);
            if ($resolved !== null) {
                $installed[] = $resolved;
            }
        }

        if ($installed === []) {
            $this->laraKubeWarn('No DB-rotatable tools are installed.');

            return [];
        }

        $options = [];
        foreach ($installed as [$t]) {
            $options[$t->value] = $t->getLabel();
        }

        $chosen = $this->flagOrPrompt(
            'tool',
            fn () => select(label: "Which tool's DB password would you like to rotate immediately?", options: $options),
            'the tool whose DB password to rotate',
            '--tool='.array_key_first($options),
        );

        foreach ($installed as $resolved) {
            if ($resolved[0]->value === $chosen) {
                return [$resolved];
            }
        }

        $this->laraKubeError("'{$chosen}' does not have a Commons database password OpenBao can rotate.");

        return [];
    }

    protected function rotateTool(string $kubectl, ClusterTool $tool, string $instance, ?string $engine): bool
    {
        $ref = $tool->dbSecretRef($instance, $engine);
        $tenant = $tool->commonsDatabases($instance, $engine)[0] ?? null;

        if ($ref === null || $tenant === null) {
            $this->laraKubeError("{$tool->getLabel()} has no wireable Commons database.");

            return false;
        }

        $roleName = $tenant;
        $wired = $this->staticRoleExists($kubectl, $roleName);

        if ($wired === false) {
            $roleName = 'tenant-'.$tenant;
            $wired = $this->staticRoleExists($kubectl, $roleName);
        }

        if (! $wired) {
            $this->laraKubeError("{$tool->getLabel()} is not wired to OpenBao rotation (no static role '{$tenant}'). Run `larakube secrets:wire --tool={$tool->value}` first.");

            return false;
        }

        $refreshTimeBefore = $this->externalSecretRefreshTime($kubectl, $ref['namespace'], "{$ref['secret']}-db");

        $rotated = false;
        $this->withSpin("Rotating {$tool->getLabel()}'s DB password in OpenBao...", function () use ($kubectl, $roleName, &$rotated): void {
            $rotated = $this->rotateStaticRole($kubectl, $roleName);
        });

        if (! $rotated) {
            $this->laraKubeError("Could not rotate the OpenBao static role for {$tool->getLabel()}.");

            return false;
        }

        $this->forceExternalSecretReconcile($kubectl, $ref['namespace'], "{$ref['secret']}-db");

        $synced = false;
        $this->withSpin("Waiting for {$ref['secret']} to sync...", function () use ($kubectl, $ref, $refreshTimeBefore, &$synced): void {
            $synced = $this->waitForExternalSecretSynced($kubectl, $ref['namespace'], "{$ref['secret']}-db", $refreshTimeBefore);
        });

        if (! $synced) {
            $this->laraKubeWarn("{$ref['secret']}-db never reported Synced — pod will pick up new password on next reconcile.");
        }

        $deployment = $tool->deploymentName($instance, $engine);
        Process::run("{$kubectl} annotate deployment {$deployment} -n {$ref['namespace']} reloader.stakater.com/auto=true --overwrite");
        $this->withSpin("Restarting {$tool->getLabel()} to pick up rotated password...", fn () => Process::run(
            "{$kubectl} rollout restart deployment/{$deployment} -n {$ref['namespace']}",
        ));

        $this->laraKubeInfo("✅ {$tool->getLabel()}'s database password has been rotated immediately in OpenBao.");

        return true;
    }

    protected function deploymentExists(string $kubectl, string $ns, string $deployment): bool
    {
        return trim(Process::run(
            "{$kubectl} get deployment {$deployment} -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';
    }
}
