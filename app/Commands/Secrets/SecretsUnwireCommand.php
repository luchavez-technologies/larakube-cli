<?php

namespace App\Commands\Secrets;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class SecretsUnwireCommand extends Command
{
    use ConfirmsDestructiveAction, InteractsWithClusterContext, InteractsWithSecrets, LaraKubeOutput, RefusesUnshippedTools, RequiresFlagsWhenNonInteractive, ResolvesEnvironmentContext, StreamsProcessOutput, SyncsClusterSecrets;

    protected $signature = 'secrets:unwire
        {environment=local : Environment whose secrets to unwire}
        {--tool= : The tool whose OpenBao DB static-role rotation should be unwired}
        {--all : Unwire all OpenBao-managed DB static roles}
        {--context= : Target a specific kube-context}
        {--force : Skip confirmation prompt}';

    protected $description = 'Unwire a tool from OpenBao DB static-role rotation and freeze its password';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = $this->secretsKubectl($context);
        $secNs = $this->secretsNamespace();

        if (! $this->secretsBackendAvailable($kubectl)) {
            $this->laraKubeError('OpenBao is not deployed on this cluster — nothing to unwire.');

            return 1;
        }

        $targets = $this->resolveTargets($kubectl);
        if ($targets === []) {
            $this->laraKubeError('No target tool specified or installed to unwire.');

            return 1;
        }

        foreach ($targets as $tool) {
            $this->unwireTool($kubectl, $secNs, $tool);
        }

        return 0;
    }

    protected function resolveTargets(string $kubectl): array
    {
        $option = (string) ($this->option('tool') ?? '');
        if ($option !== '') {
            $tool = ClusterTool::tryFrom($option);
            if ($tool === null) {
                $this->laraKubeError("Tool '{$option}' has no OpenBao DB static-role configuration.");

                return [];
            }
            if ($this->refuseUnshippedTool($tool)) {
                return [];
            }
            if ($tool->dbSecretRef() === null) {
                $this->laraKubeError("Tool '{$option}' has no OpenBao DB static-role configuration.");

                return [];
            }

            return [$tool];
        }

        $installed = [];
        foreach (ClusterTool::shippedCases() as $candidate) {
            if ($candidate->dbSecretRef() === null) {
                continue;
            }
            if ($this->isToolInstalled($kubectl, $candidate)) {
                $installed[] = $candidate;
            }
        }

        if ($this->option('all')) {
            return $installed;
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Passing --tool or --all is required when running in non-interactive mode.');

            return [];
        }

        if ($installed === []) {
            $this->laraKubeWarn('No DB-wired tools are currently installed.');

            return [];
        }

        $options = [];
        foreach ($installed as $t) {
            $options[$t->value] = $t->getLabel();
        }

        $selected = select(
            label: 'Which tool do you want to unwire from OpenBao DB rotation?',
            options: $options,
        );

        return [ClusterTool::from($selected)];
    }

    protected function isToolInstalled(string $kubectl, ClusterTool $tool): bool
    {
        $ref = $tool->dbSecretRef();
        if ($ref === null) {
            return false;
        }

        return trim(Process::run("{$kubectl} get secret {$ref['secret']} -n {$ref['namespace']} --no-headers --ignore-not-found")->output()) !== '';
    }

    protected function unwireTool(string $kubectl, string $secNs, ClusterTool $tool): bool
    {
        $ref = $tool->dbSecretRef();
        if ($ref === null) {
            return false;
        }

        $tenant = $tool->commonsDatabases()[0] ?? $tool->value;
        $roleState = $this->staticRoleExists($kubectl, $tenant);

        if ($roleState === false) {
            $this->laraKubeInfo("ℹ️ {$tool->getLabel()} is not currently OpenBao-managed.");

            return true;
        }

        if (! $this->confirmDestructive(["Unwiring OpenBao DB password rotation for {$tool->getLabel()}"])) {
            return false;
        }

        $ok = true;
        $this->withSpin("Unwiring OpenBao DB rotation for {$tool->getLabel()}...", function () use ($kubectl, $ref, $tenant, &$ok): void {
            Process::run("{$kubectl} delete externalsecret {$ref['secret']}-db -n {$ref['namespace']} --ignore-not-found");
            Process::run("{$kubectl} delete vaultdynamicsecret.generators.external-secrets.io {$ref['secret']}-db -n {$ref['namespace']} --ignore-not-found");
            $ok = $this->deleteStaticRole($kubectl, $tenant);
        });

        if (! $ok) {
            $this->laraKubeError("Failed to unwire OpenBao DB rotation for {$tool->getLabel()}.");

            return false;
        }

        $this->laraKubeInfo("✅ {$tool->getLabel()}'s DB password is now static (frozen at its last rotated value) — re-run `secrets:wire` anytime to hand it back.");

        return true;
    }
}
