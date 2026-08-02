<?php

namespace App\Commands\Secrets;

use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class SecretsUnsealCommand extends Command
{
    use DeploysClusterTool, InteractsWithSecrets, LaraKubeOutput;

    protected $signature = 'secrets:unseal
        {environment=local : Environment whose OpenBao to unseal}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Unseal OpenBao using its stored unseal key';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->secretsKubectl($context);
        $ns = $this->secretsNamespace();

        if (! $this->isOpenBaoBootstrapped($kubectl, $ns)) {
            $this->laraKubeError('OpenBao is not deployed. Run `larakube secrets:init` first.');

            return 1;
        }

        $initStatus = $this->openBaoApi($kubectl, 'GET', '/v1/sys/init');
        if ($initStatus === null) {
            $this->laraKubeError('Could not reach OpenBao. Is the openbao-backend pod running?');

            return 1;
        }

        if (! ($initStatus['initialized'] ?? false)) {
            $this->laraKubeError('OpenBao has never been initialized. Run `larakube secrets:init` first.');

            return 1;
        }

        if (! $this->unsealOpenBao($kubectl, $ns)) {
            $this->laraKubeError('Could not unseal OpenBao.');

            return 1;
        }

        $this->laraKubeInfo('✅ OpenBao is unsealed.');

        return 0;
    }
}
