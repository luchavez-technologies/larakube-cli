<?php

namespace App\Commands\Secrets;

use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class SecretsSealCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithSecrets, LaraKubeOutput;

    protected $signature = 'secrets:seal
        {environment=local : Environment whose OpenBao to seal}
        {--context= : Target a specific kube-context (defaults to current context)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Seal OpenBao immediately — an incident-response lever that blocks all secret access';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->secretsKubectl($context);
        $ns = $this->secretsNamespace();

        if (! $this->isOpenBaoBootstrapped($kubectl, $ns)) {
            $this->laraKubeError('OpenBao is not deployed.');

            return 1;
        }

        if (! $this->confirmDestructive([
            "Sealing OpenBao in '{$env}' blocks ALL secret reads and writes immediately — every rotated password, synced ExternalSecret, and tool relying on it stops working until it's unsealed again.",
        ])) {
            return 0;
        }

        if (! $this->sealOpenBao($kubectl, $ns)) {
            $this->laraKubeError('Could not seal OpenBao.');

            return 1;
        }

        $this->laraKubeInfo('✅ OpenBao is sealed.');
        $this->line("  <fg=gray>Unseal it with</> <fg=blue>larakube secrets:unseal {$env}</>");

        return 0;
    }
}
