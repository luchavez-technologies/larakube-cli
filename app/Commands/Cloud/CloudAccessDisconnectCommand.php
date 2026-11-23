<?php

namespace App\Commands\Cloud;

use App\Facades\State;
use App\Services\Kubectl;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Remove LaraKube Cloud's read-only access and prove it is gone. With --kubeconfig the read-only
 * identity removes itself, so no admin credential is needed. Safe to re-run.
 */
class CloudAccessDisconnectCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'cloud:access:disconnect
        {--context= : An admin kube-context to disconnect}
        {--kubeconfig= : The read-only kubeconfig, for the identity to remove itself}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Remove LaraKube Cloud\'s read-only access from a cluster';

    /** @var list<string> */
    private array $manualSteps = [];

    public function handle(): int
    {
        $json = (bool) $this->flag('json');
        if ($json) {
            $this->enableJsonMode();
        }

        $kubeconfig = (string) $this->option('kubeconfig');
        $exit = $kubeconfig !== '' ? $this->removeItself($kubeconfig) : $this->disconnect();

        if ($json) {
            $this->jsonOutput($exit === 0
                ? ['success' => true, 'disconnected' => true, 'error' => null]
                : ['success' => false, 'error' => State::lastError() ?? 'Access could not be confirmed removed.', 'manualSteps' => $this->manualSteps]);
        }

        return $exit;
    }

    /**
     * What a customer runs over their own SSH key when Cloud can't finish the removal.
     *
     * @return list<string>
     */
    public static function manualSteps(): array
    {
        $identity = CloudAccessConnectCommand::IDENTITY;
        $observer = CloudAccessConnectCommand::OBSERVER;
        $selfRemoval = CloudAccessConnectCommand::SELF_REMOVAL;

        return [
            'ssh larakube@<your-server>',
            "sudo k3s kubectl delete clusterrolebinding {$observer} larakube-cluster-user-{$identity} --ignore-not-found",
            "sudo k3s kubectl delete clusterrole {$observer} --ignore-not-found",
            "sudo k3s kubectl delete rolebinding,role {$selfRemoval} -n larakube-access --ignore-not-found",
            "sudo k3s kubectl delete serviceaccount {$identity} -n larakube-access --ignore-not-found",
        ];
    }

    /** The role goes first: its binding follows, and the identity keeps the right to delete itself. */
    private function removeItself(string $kubeconfig): int
    {
        if (! is_file($kubeconfig)) {
            return $this->failWithSteps('The read-only kubeconfig was not found.');
        }

        $cluster = Kubectl::forKubeconfig($kubeconfig);
        $probe = $this->tokenStatus($cluster);

        if ($probe === 'revoked') {
            $this->laraKubeInfo('✅ LaraKube Cloud no longer has access to this cluster.');

            return 0;
        }

        if ($probe === 'unreachable') {
            return $this->failWithSteps('Could not reach the cluster, so LaraKube Cloud\'s access was not removed.');
        }

        $role = $cluster->raw(['delete', 'clusterrole', CloudAccessConnectCommand::OBSERVER, '--ignore-not-found']);
        $account = $cluster->raw(['delete', 'serviceaccount', CloudAccessConnectCommand::IDENTITY, '-n', 'larakube-access', '--ignore-not-found']);

        if (! $role->ok || ! $account->ok) {
            return $this->failWithSteps('The cluster refused the removal, so LaraKube Cloud\'s access may still be active.');
        }

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            if ($this->tokenStatus($cluster) === 'revoked') {
                $this->laraKubeInfo('✅ LaraKube Cloud no longer has access to this cluster.');

                return 0;
            }

            Sleep::sleep(2);
        }

        return $this->failWithSteps('The removal ran, but the token still works.');
    }

    private function tokenStatus(Kubectl $cluster): string
    {
        $result = $cluster->raw(['get', 'nodes', '-o', 'name', '--request-timeout=10s']);

        if ($result->ok) {
            return 'active';
        }

        return stripos($result->error.$result->output, 'unauthorized') !== false ? 'revoked' : 'unreachable';
    }

    private function failWithSteps(string $message): int
    {
        $this->manualSteps = self::manualSteps();
        $this->laraKubeError($message.' Remove it from the server over SSH:');

        foreach ($this->manualSteps as $step) {
            $this->line("  <fg=yellow>{$step}</>");
        }

        return 1;
    }

    private function disconnect(): int
    {
        $context = (string) $this->option('context');
        if ($context === '') {
            $this->laraKubeError('Pass --context or --kubeconfig.');

            return 1;
        }

        $cluster = Kubectl::forContext($context);
        $observer = CloudAccessConnectCommand::OBSERVER;
        $selfRemoval = CloudAccessConnectCommand::SELF_REMOVAL;

        $cluster->raw(['delete', 'clusterrolebinding', $observer, '--ignore-not-found']);
        $cluster->raw(['delete', 'clusterrole', $observer, '--ignore-not-found']);
        $cluster->raw(['delete', 'rolebinding,role', $selfRemoval, '-n', 'larakube-access', '--ignore-not-found']);
        Artisan::call('cluster:revoke', [
            '--name' => CloudAccessConnectCommand::IDENTITY,
            '--context' => $context,
            '--force' => true,
            '--no-interaction' => true,
        ], new BufferedOutput);

        $account = $cluster->raw(['get', 'serviceaccount', CloudAccessConnectCommand::IDENTITY, '-n', 'larakube-access', '--ignore-not-found', '-o', 'name']);
        $bindings = $cluster->raw(['get', 'clusterrolebinding', $observer, 'larakube-cluster-user-'.CloudAccessConnectCommand::IDENTITY, '--ignore-not-found', '-o', 'name']);

        // A failed check proves nothing, so it must never read as "access is gone".
        if (! $account->ok || ! $bindings->ok) {
            return $this->failWithSteps('Could not reach the cluster, so LaraKube Cloud\'s access was not confirmed removed.');
        }

        if (trim($account->output) !== '' || trim($bindings->output) !== '') {
            return $this->failWithSteps('LaraKube Cloud\'s access is still on the cluster.');
        }

        $this->laraKubeInfo('✅ LaraKube Cloud no longer has access to this cluster.');

        return 0;
    }
}
