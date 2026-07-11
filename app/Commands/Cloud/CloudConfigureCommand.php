<?php

namespace App\Commands\Cloud;

use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithScopedRbac;
use App\Traits\LaraKubeOutput;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;

class CloudConfigureCommand extends Command
{
    use ConfiguresCloudEnvironment, GeneratesProjectInfrastructure, InteractsWithEnvironments, InteractsWithGlobalConfig, InteractsWithProjectConfig, InteractsWithScopedRbac, LaraKubeOutput;

    /**
     * The name and signature of the console command. The bare command runs the
     * full guided setup (deploy target + hosts → optional Commons → CI).
     * `--only` re-runs a single step instead — replaces the old
     * `cloud:configure:registry` / `:gha` / `:gitlab` sub-commands. There's no
     * direct replacement for the old `:base`: its host half is `--only=hosts`
     * (now covering every client-facing host, not just web); its deploy-target
     * half only changes via a full guided re-run (`cloud:configure {env}`).
     */
    protected $signature = 'cloud:configure
        {environment? : The environment to configure}
        {--only= : Re-run just one step instead of the full guided flow: registry|ci|hosts}
        {--rotate : Revoke the current deploy token/secrets and mint fresh ones (use after a leak) — only with --only=ci}
        {--ingress= : Ingress controller slug for the environment (skips the prompt)}
        {--managed= : Comma-separated externally-managed services; pass an empty value for none (skips the prompt)}
        {--web-hosts= : Comma-separated additional web hostnames; pass an empty value to clear (skips the prompt)}
        {--registry-provider= : Legacy alias for the --registry option}
        {--registry= : Container registry provider: ghcr|dockerhub|gitlab|gitea (skips the prompt)}
        {--platform= : CI/CD platform: github|gitlab (skips the prompt)}
        {--image= : Registry image repository path, owner/repo (skips the prompt)}
        {--branch= : Git branch that triggers the CI deployment (skips the prompt)}
        {--strict : Also fail security gates on HIGH severity (not just CRITICAL)}
        {--skip-audit : Generate the lean pipeline without security audit steps}
        {--with-tests : Include the PHPUnit/Pest suite in the audit phase}';

    /**
     * The console command description.
     */
    protected $description = 'Guided cloud setup for an environment — server/context, web host, optional Commons, and CI';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = $this->argument('environment');
        $only = $this->option('only');

        try {
            return match ($only) {
                null => $this->configureAll($environment),
                'registry' => $this->configureRegistry($environment),
                'ci' => $this->configureCi($environment, (bool) $this->option('rotate')),
                'hosts' => $this->configureHosts($environment),
                default => $this->unsupportedOnlyValue($only),
            };
        } catch (InvalidArgumentException $e) {
            // Invalid or missing non-interactive flag values surface here
            // (see GathersEnvironmentData) — one boundary instead of exit
            // codes threaded through every value-returning trait method.
            $this->laraKubeError($e->getMessage());

            return 1;
        }
    }

    private function unsupportedOnlyValue(string $only): int
    {
        $this->laraKubeError("Unknown --only value '{$only}'. Use one of: registry, ci, hosts.");

        return 1;
    }
}
