<?php

namespace App\Commands\Cloud;

use App\Services\Kubectl;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesDevBoxTunnel;
use App\Traits\ProvisionsK3sNode;
use App\Traits\ReadsCommandOptions;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class DevboxConnectCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, LaraKubeOutput, ManagesDevBoxTunnel, ProvisionsK3sNode, ReadsCommandOptions;

    protected $signature = 'devbox:connect
        {--stack-name= : The dev box to connect to}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Open an SSH tunnel to a dev box\'s cluster and give this computer a kube-context for it';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        $config = $this->getGlobalConfig();
        $stack = $config->findStack((string) $this->flag('stack-name'));

        if ($stack === null || $stack->role !== 'dev' || ! $stack->ip || ! $stack->sshKey) {
            return $this->failed('That is not a dev box this computer made. List them with larakube cloud:stacks.');
        }

        $context = $this->devBoxContextName($stack->name);
        $port = $stack->tunnelPort;
        $alive = $this->tunnelAlive($stack);

        // A tunnel whose port was never recorded cannot be told apart from a stale one: start over.
        if ($alive && $port === null) {
            $this->stopTunnel($stack);
            $alive = false;
        }

        $contextExists = $this->contextExists($context);

        // A port that is not ours and is taken by something else cannot be reused.
        if ($port === null || (! $alive && ! $this->localPortFree($port))) {
            $taken = collect($config->stacks)->pluck('tunnelPort')->filter()->map(fn ($p): int => (int) $p)->all();
            $port = $this->pickTunnelPort($taken);
            $contextExists = false;
        }

        // Recorded before the tunnel starts, so what is running is always what the config says.
        $stack->tunnelPort = $port;
        $config->putStack($stack);
        $config->save();

        if (! $alive && ! $this->startTunnel($stack, $port)) {
            return $this->failed("Could not open an SSH tunnel to {$stack->ip}. Check the box is running and reachable.");
        }

        if (! $contextExists && ! $this->syncKubeconfig('larakube', $stack->ip, 22, $stack->sshKey, $context, "https://127.0.0.1:{$port}")) {
            return $this->failed('Could not bring the box\'s kubeconfig to this computer.');
        }

        $stack->context = $context;
        $config->putStack($stack);
        $config->save();

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'context' => $context, 'port' => $port]);

            return 0;
        }

        $this->laraKubeInfo("Connected. Context: {$context} (kubectl --context={$context} …)");

        return 0;
    }

    private function contextExists(string $context): bool
    {
        $result = Process::run(Kubectl::forKubeconfig([home_path('.kube/config')])->prefix().' config get-contexts -o name');

        return in_array($context, array_map('trim', explode("\n", $result->output())), true);
    }

    private function failed(string $message): int
    {
        $this->laraKubeError($message);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => false, 'error' => $message]);
        }

        return 1;
    }
}
