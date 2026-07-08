<?php

namespace App\Commands;

use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class ContextRemoveCommand extends Command
{
    use InteractsWithClusterContext, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'context:remove
        {name? : The context to remove (omit to pick from a list)}
        {--force : Skip the confirmation prompt}';

    /**
     * The console command description.
     */
    protected $description = 'Remove a stale Kubernetes context (e.g. after deleting a droplet)';

    /**
     * Execute the console command.
     *
     * Drops the context plus its matching cluster and user entries — LaraKube
     * names all three identically for a `larakube-<ip>` context, so a deleted
     * droplet otherwise leaves all three orphaned in the kubeconfig.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $target = $this->argument('name') ?: $this->askForClusterContext();

        if (! $target) {
            $this->laraKubeError('No Kubernetes contexts found or selection cancelled.');

            return 1;
        }

        $current = $this->kubectlCurrentContext();

        $this->laraKubeWarn("Removing context, cluster and user entries named '{$target}' from your kubeconfig.");
        if ($target === $current) {
            $this->laraKubeWarn("'{$target}' is your CURRENT context — you'll have no active context afterwards.");
        }

        if (! $this->option('force') && ! confirm("Remove context '{$target}'?", false)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $t = escapeshellarg($target);
        $result = Process::run("kubectl config delete-context {$t}");
        if (! $result->successful()) {
            $this->laraKubeError("Failed to remove context '{$target}':\n".trim($result->output().$result->errorOutput()));

            return 1;
        }

        // Best-effort: for larakube-<ip> contexts the cluster + user share the name.
        // For other contexts these simply won't match and are left untouched.
        Process::run("kubectl config delete-cluster {$t}");
        Process::run("kubectl config delete-user {$t}");

        $this->laraKubeInfo("✅ Removed context '{$target}' (and its cluster/user entries).");

        return 0;
    }
}
