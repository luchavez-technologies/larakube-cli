<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class MailRestartCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithTraefik, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'mail:restart
        {environment? : Environment the mail server runs in — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}';

    protected $description = 'Restart Stalwart — applies the config written by the first-run setup wizard';

    public function handle(): int
    {
        $this->renderHeader();

        $env = $this->resolveEnvironment();

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError("No Stalwart mail server found in {$ns} for '{$env}'. Deploy it with `larakube mail:init {$env}` first.");

            return 1;
        }

        $this->withSpin('Restarting Stalwart...', fn () => Process::run(
            "{$kubectl} rollout restart deployment/stalwart -n {$ns}",
        ));

        $this->withSpin('Waiting for Stalwart to come back...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deployment/stalwart -n {$ns} --timeout=180s",
            190,
        ));

        $this->withSpin('Refreshing Traefik routing...', fn () => $this->restartTraefikIngress($kubectl));

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Stalwart restarted ({$env}).");
        $this->laraKubeLine('  If you just finished the setup wizard, <fg=gray>/admin</> now shows a login instead of the wizard.');

        return 0;
    }

    /**
     * Which environment the mail server lives in — explicit arg / --env wins,
     * else prompt with the project's environments (mirrors mail:init).
     */
    protected function resolveEnvironment(): string
    {
        $explicit = (string) ($this->argument('environment') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        if ($this->option('no-interaction')) {
            return 'local';
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $envs = $config ? array_merge(['local'], $config->getCloudEnvironments()) : ['local'];

        return select(
            label: 'Which environment is the mail server in?',
            options: array_combine($envs, $envs),
            default: 'local',
        );
    }
}
