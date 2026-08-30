<?php

namespace App\Commands\Secrets;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\PicksRegisteredTool;
use App\Traits\RefusesUnshippedTools;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class SecretsUnwireCommand extends Command
{
    use ConfirmsDestructiveAction, InteractsWithClusterContext, InteractsWithSecrets, LaraKubeOutput, PicksRegisteredTool, RefusesUnshippedTools, RequiresFlagsWhenNonInteractive, ResolvesEnvironmentContext, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets;

    protected $signature = 'secrets:unwire
        {environment=local : Environment whose secrets to unwire}
        {--tool= : The tool whose OpenBao DB static-role rotation should be unwired}
        {--domain= : The instance to target (e.g. --domain=blog.example.com). Omit for the default instance. Ignored with --all}
        {--engine= : Specific engine to target explicitly, skipping auto-detection (e.g. --engine=pocketbase)}
        {--all : Unwire all OpenBao-managed DB static roles}
        {--context= : Target a specific kube-context}
        {--force : Skip confirmation prompt}';

    protected $description = 'Unwire a tool from OpenBao DB static-role rotation and freeze its password';

    protected ?string $pickedHost = null;

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

        $domainOption = (string) ($this->option('domain') ?: '');
        $engine = (string) ($this->option('engine') ?: '') ?: null;

        foreach ($targets as $tool) {
            // --all deliberately ignores --domain, matching secrets:wire: one
            // domain cannot mean something sensible across many tools.
            // Explicit --domain wins; otherwise the instance the picker
            // resolved. --all ignores both: one domain cannot mean something
            // sensible across many tools.
            $host = $domainOption !== '' ? $this->sanitizeDomainInput($domainOption) : (string) $this->pickedHost;
            $instance = (! $this->option('all') && $host !== '')
                ? $this->resolveInstanceForDomain($kubectl, $tool, $host)
                : null;

            $this->unwireTool($kubectl, $secNs, $tool, $instance, $engine);
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
            if (! $tool->hasSecretsWire()) {
                $this->laraKubeError("Tool '{$option}' has no OpenBao DB static-role configuration.");

                return [];
            }

            return [$tool];
        }

        // hasSecretsWire() is the pair's marker contract (HasOpenbaoSync),
        // replacing a probe for dbSecretRef() being non-null -- each wire pair
        // used to infer capability from a different accessor.
        //
        // Then filtered to tools OpenBao is ACTUALLY rotating, mirroring
        // sso:unwire. Offering a tool that was never wired makes a no-op look
        // like it did something; --all keeps the wider net because it is
        // explicitly a sweep.
        $installed = [];
        foreach (ClusterTool::shippedCases() as $candidate) {
            if (! $candidate->hasSecretsWire()) {
                continue;
            }
            if (! $this->isToolInstalled($kubectl, $candidate)) {
                continue;
            }
            if (! $this->option('all') && $this->staticRoleExists($kubectl, $candidate->commonsDatabases()[0] ?? $candidate->value) !== true) {
                continue;
            }

            $installed[] = $candidate;
        }

        if ($this->option('all')) {
            return $installed;
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Passing --tool or --all is required when running in non-interactive mode.');

            return [];
        }

        if ($installed === []) {
            $this->laraKubeWarn('No tools currently have their DB password managed by OpenBao.');

            return [];
        }

        $picked = $this->pickRegisteredTool(
            $kubectl,
            'Which tool do you want to unwire from OpenBao DB rotation?',
            fn (ClusterTool $candidate): bool => in_array($candidate, $installed, true),
            emptyMessage: 'No tools currently have their DB password managed by OpenBao.',
        );

        if ($picked === null) {
            return [];
        }

        $this->pickedHost = $picked[1];

        return [$picked[0]];
    }

    protected function isToolInstalled(string $kubectl, ClusterTool $tool): bool
    {
        $ref = $tool->dbSecretRef();
        if ($ref === null) {
            return false;
        }

        return trim(Process::run("{$kubectl} get secret {$ref['secret']} -n {$ref['namespace']} --no-headers --ignore-not-found")->output()) !== '';
    }

    protected function unwireTool(string $kubectl, string $secNs, ClusterTool $tool, ?string $instance = null, ?string $engine = null): bool
    {
        // Same two accessors, with the same arguments, as secrets:wire's
        // wireTool(). Resolving them without the instance unwired the DEFAULT
        // instance's static role no matter which one you asked for -- and
        // reported success for it.
        $ref = $tool->dbSecretRef($instance, $engine);
        if ($ref === null) {
            return false;
        }

        $tenant = $tool->commonsDatabases($instance, $engine)[0] ?? $tool->value;
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
