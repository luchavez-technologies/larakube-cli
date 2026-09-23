<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Exceptions\AmbiguousEnvironmentException;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

/**
 * One environment-resolution rule for every `{tool}:init`.
 *
 * This was copy-pasted into 23 init commands, identical apart from the prompt
 * label — and every copy carried the same bug:
 *
 *     if ($this->option('no-interaction') || $this->option('domain')) {
 *         return 'local';
 *     }
 *
 * Passing `--domain` forced the environment to `local`. So
 * `larakube secrets:init --domain=example.com` resolved the RIGHT hostname
 * (secrets.example.com) but the WRONG environment, which then cascaded:
 * resolveToolContext('local') returns a null context, so the manifests applied
 * to whatever kube-context happened to be current, and the templates rendered
 * with isLocal=true — a real public hostname on a local-TLS ingress. That is
 * the "hit and miss when I just set the domain" behaviour.
 *
 * `--domain` answers "what hostname", never "which cluster". The two are now
 * separate: --domain only suppresses the HOST prompt (ResolvesToolHost already
 * handles that), and the environment comes from the positional, an explicit
 * --context flag, an explicit --no-interaction default, or a prompt.
 */
trait ResolvesToolEnvironment
{
    /**
     * Target kube-context resolved or chosen during environment resolution.
     */
    protected ?string $resolvedToolContext = null;

    public function getResolvedToolContext(): ?string
    {
        return $this->resolvedToolContext;
    }

    /**
     * @param  ClusterTool|string  $tool  Supplies the human label for the prompt.
     * @param  ConfigData|null  $config  Pass one if the caller already loaded it.
     */
    protected function resolveToolEnvironment(ClusterTool|string $tool, ?ConfigData $config = null): string
    {
        $hasContextOption = method_exists($this, 'hasOption') ? $this->hasOption('context') : true;
        $contextOption = $hasContextOption ? (string) ($this->option('context') ?? '') : '';
        if ($contextOption !== '') {
            $this->resolvedToolContext = $contextOption;
        }

        $hasEnvArg = method_exists($this, 'hasArgument') ? $this->hasArgument('environment') : true;
        $explicit = $hasEnvArg ? (string) ($this->argument('environment') ?: '') : '';
        if ($explicit !== '') {
            return $explicit;
        }

        if ($contextOption !== '') {
            $config ??= $this->loadProjectConfigIfAny();
            if ($config !== null) {
                if ($matchedEnv = $this->findEnvironmentForContext($config, $contextOption)) {
                    return $matchedEnv;
                }
            }

            return Kubectl::isLocalContextName($contextOption) ? 'local' : 'production';
        }

        $config ??= $this->loadProjectConfigIfAny();
        $known = $config ? $config->getCloudEnvironments() : [];

        $toolLabel = is_string($tool) ? $tool : $tool->getLabel();
        $toolCommand = is_string($tool) ? strtolower($tool).':init' : $tool->initCommand();

        // A domain without an environment or context is genuinely ambiguous — refuse rather
        // than guess. This is the specific silent failure this trait exists for.
        $hasDomainOption = method_exists($this, 'hasOption') ? $this->hasOption('domain') : true;
        $domain = $hasDomainOption ? (string) ($this->option('domain') ?? '') : '';
        if ($domain !== '') {
            throw new AmbiguousEnvironmentException($toolCommand, $domain, $known);
        }

        // No domain and nothing to prompt with: `local` is the documented
        // default for an omitted {environment?} and is safe — it deploys to the
        // current context with local TLS, which is what a bare run means.
        if ($this->option('no-interaction')) {
            return 'local';
        }

        if ($config !== null) {
            $envs = array_merge(['local'], $known);

            return select(
                label: "Which environment is this {$toolLabel} install for?",
                options: array_combine($envs, $envs),
                default: 'local',
                hint: "Local uses your dev TLD; a cloud env asks for + persists the {$toolLabel} host.",
            );
        }

        // Standalone Mode (outside a project): prompt for Kubernetes context directly
        $contexts = $this->getAvailableKubeContexts();
        if (! empty($contexts)) {
            $currentContext = $this->getCurrentKubeContext();

            $chosenContext = select(
                label: "Which Kubernetes context would you like to target for {$toolLabel}?",
                options: array_combine($contexts, $contexts),
                default: in_array($currentContext, $contexts, true) ? $currentContext : null,
            );

            $this->resolvedToolContext = $chosenContext;

            return Kubectl::isLocalContextName($chosenContext) ? 'local' : 'production';
        }

        return 'local';
    }

    protected function findEnvironmentForContext(ConfigData $config, string $context): ?string
    {
        foreach ($config->getCloudEnvironments() as $env) {
            $cloud = $config->getCloud($env);
            $recorded = $cloud?->context ?: ($cloud?->ip ? 'larakube-'.$cloud->ip : null);
            if ($recorded === $context) {
                return $env;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function getAvailableKubeContexts(): array
    {
        if (method_exists($this, 'availableKubeContexts')) {
            return $this->availableKubeContexts();
        }

        $lines = explode("\n", Process::run(Kubectl::forContext(null)->prefix().' config get-contexts -o name')->output());

        return array_values(array_filter(array_map('trim', $lines)));
    }

    protected function getCurrentKubeContext(): string
    {
        if (method_exists($this, 'currentKubeContext')) {
            return $this->currentKubeContext();
        }

        return trim(Process::run(Kubectl::forContext(null)->prefix().' config current-context')->output());
    }

    protected function loadProjectConfigIfAny(): ?ConfigData
    {
        $projectPath = getcwd();

        return file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;
    }
}
