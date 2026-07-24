<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Exceptions\AmbiguousEnvironmentException;

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
 * --no-interaction default, or a prompt.
 */
trait ResolvesToolEnvironment
{
    /**
     * @param  ClusterTool  $tool  Supplies the human label for the prompt.
     * @param  ConfigData|null  $config  Pass one if the caller already loaded it.
     */
    protected function resolveToolEnvironment(ClusterTool $tool, ?ConfigData $config = null): string
    {
        $explicit = (string) ($this->argument('environment') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        $config ??= $this->loadProjectConfigIfAny();
        $known = $config ? $config->getCloudEnvironments() : [];

        // A domain without an environment is genuinely ambiguous — refuse rather
        // than guess. This is the specific silent failure this trait exists for.
        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            throw new AmbiguousEnvironmentException($tool->initCommand(), $domain, $known);
        }

        // No domain and nothing to prompt with: `local` is the documented
        // default for an omitted {environment?} and is safe — it deploys to the
        // current context with local TLS, which is what a bare run means.
        if ($this->option('no-interaction')) {
            return 'local';
        }

        $envs = array_merge(['local'], $known);

        return select(
            label: "Which environment is this {$tool->getLabel()} install for?",
            options: array_combine($envs, $envs),
            default: 'local',
            hint: "Local uses your dev TLD; a cloud env asks for + persists the {$tool->getLabel()} host.",
        );
    }

    protected function loadProjectConfigIfAny(): ?ConfigData
    {
        $projectPath = getcwd();

        return file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;
    }
}
