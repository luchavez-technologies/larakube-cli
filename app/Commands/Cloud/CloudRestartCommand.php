<?php

namespace App\Commands\Cloud;

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * Reboots a server `cloud:create` made and waits until it is serving again:
 * SSH first, then the Kubernetes API. Apps and tools on it are unavailable
 * for a minute or two, then come back on their own (k3s starts at boot).
 */
class CloudRestartCommand extends Command
{
    use InteractsWithGlobalConfig, InteractsWithRemoteSsh, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'cloud:restart
        {--stack= : The server to restart. Omit to pick from the registry.}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Restart a server created with cloud:create and wait until it is back';

    public function handle(): int
    {
        $this->renderHeader();

        $vps = array_filter($this->getGlobalConfig()->getStacks(), fn ($stack): bool => $stack->kind === 'vps' && $stack->ip !== null);

        if ($vps === []) {
            $this->laraKubeWarn('No server to restart. (Only servers made with cloud:create can be restarted.)');

            return 1;
        }

        $name = (string) ($this->flag('stack') ?: select(
            label: 'Which server do you want to restart?',
            options: array_map(fn ($stack): string => "{$stack->name}  ({$stack->ip})", $vps),
        ));

        $stack = $vps[$name] ?? null;

        if ($stack === null) {
            $this->laraKubeError("No restartable server named '{$name}'. Only servers made with cloud:create can be restarted.");

            return 1;
        }

        if ($stack->sshKey === null || ! is_file($stack->sshKey)) {
            $this->laraKubeError("The SSH key for '{$name}' is not on this machine, so it cannot be restarted from here.");

            return 1;
        }

        if (! $this->flag('force') && ! confirm("Restart {$name}? Everything on it is unavailable for a minute or two.", default: false)) {
            $this->laraKubeInfo('Left as it is.');

            return 0;
        }

        $user = 'larakube';
        $port = '22';

        if (! $this->testSsh($user, $stack->ip, $port, $stack->sshKey)) {
            $this->laraKubeError("Cannot reach {$name} over SSH at {$stack->ip}, so it was not restarted.");

            return 1;
        }

        $this->laraKubeInfo("Restarting {$name}...");

        // The reboot ends this SSH session at once: a non-zero exit is the expected result.
        Process::timeout(15)->run("ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i {$stack->sshKey} -p {$port} {$user}@{$stack->ip} 'sudo reboot'");

        // Let it actually go down before polling, or the first poll answers from the old boot.
        Sleep::sleep(10);

        if (! $this->waitForSsh($user, $stack->ip, $port, $stack->sshKey)) {
            $this->laraKubeError("{$name} did not come back over SSH. Check it in your provider's console.");

            return 1;
        }

        if ($stack->context !== null && ! $this->waitForKubernetes($stack->context)) {
            $this->laraKubeWarn("{$name} is back, but Kubernetes is not answering yet. Give it another minute, then check the server's page.");

            return 1;
        }

        $this->laraKubeInfo("✅ {$name} restarted and serving again.");

        return 0;
    }

    private function waitForKubernetes(string $context, int $attempts = 36, int $delay = 5): bool
    {
        $this->laraKubeInfo('Waiting for Kubernetes...');

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if (Process::timeout(15)->run(['kubectl', "--context={$context}", 'get', '--raw=/readyz', '--request-timeout=5s'])->successful()) {
                return true;
            }

            Sleep::sleep($delay);
        }

        return false;
    }
}
