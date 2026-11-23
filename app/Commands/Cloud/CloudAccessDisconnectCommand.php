<?php

namespace App\Commands\Cloud;

use App\Facades\State;
use App\Services\Kubectl;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\BufferedOutput;

/** Remove LaraKube Cloud's read-only access from a cluster and prove it is gone. Safe to re-run. */
class CloudAccessDisconnectCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'cloud:access:disconnect
        {--context= : The kube-context to disconnect}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Remove LaraKube Cloud\'s read-only access from a cluster';

    public function handle(): int
    {
        $json = (bool) $this->flag('json');
        if ($json) {
            $this->enableJsonMode();
        }

        $exit = $this->disconnect();

        if ($json) {
            $this->jsonOutput($exit === 0
                ? ['success' => true, 'disconnected' => true, 'error' => null]
                : ['success' => false, 'error' => State::lastError() ?? 'Access could not be confirmed removed.']);
        }

        return $exit;
    }

    private function disconnect(): int
    {
        $context = (string) $this->option('context');
        if ($context === '') {
            $this->laraKubeError('--context is required.');

            return 1;
        }

        $cluster = Kubectl::forContext($context);
        $observer = CloudAccessConnectCommand::OBSERVER;

        $cluster->raw(['delete', 'clusterrolebinding', $observer, '--ignore-not-found']);
        $cluster->raw(['delete', 'clusterrole', $observer, '--ignore-not-found']);
        Artisan::call('cluster:revoke', [
            '--name' => CloudAccessConnectCommand::IDENTITY,
            '--context' => $context,
            '--force' => true,
            '--no-interaction' => true,
        ], new BufferedOutput);

        $left = trim($cluster->raw(['get', 'serviceaccount', CloudAccessConnectCommand::IDENTITY, '-n', 'larakube-access', '--ignore-not-found', '-o', 'name'])->output);
        $bindings = trim($cluster->raw(['get', 'clusterrolebinding', $observer, 'larakube-cluster-user-'.CloudAccessConnectCommand::IDENTITY, '--ignore-not-found', '-o', 'name'])->output);

        if ($left !== '' || $bindings !== '') {
            $this->laraKubeError('LaraKube Cloud\'s access is still on the cluster. Run this again.');

            return 1;
        }

        $this->laraKubeInfo('✅ LaraKube Cloud no longer has access to this cluster.');

        return 0;
    }
}
