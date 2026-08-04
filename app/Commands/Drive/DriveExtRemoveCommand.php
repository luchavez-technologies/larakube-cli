<?php

namespace App\Commands\Drive;

use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithOcisExtensions;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class DriveExtRemoveCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithOcisExtensions, LaraKubeOutput, ResolvesToolEnvironment;

    protected $signature = 'drive:ext:remove
        {environment? : Target environment — "local" (default) or cloud.}
        {--context=   : Target a specific kube-context}
        {--extension= : Specific web extension ID to remove}';

    protected $description = 'Remove a web extension from Drive (oCIS)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->contextKubectl($context);
        $ns = ClusterTool::DRIVE->namespace();

        $installed = $this->getInstalledOcisExtensions($kubectl, $ns);

        if ($installed === []) {
            $this->laraKubeInfo('No oCIS web extensions are currently installed.');

            return 0;
        }

        $specified = (string) $this->option('extension');
        if ($specified !== '') {
            $target = $specified;
        } else {
            $target = select(
                label: 'Select oCIS Web Extension to remove:',
                options: array_combine($installed, $installed),
            );
        }

        $this->withSpin("Removing oCIS extension '{$target}'...", function () use ($kubectl, $ns, $target) {
            return $this->removeOcisExtension($kubectl, $ns, $target);
        });

        $this->withSpin('Restarting Drive (oCIS) deployment...', function () use ($kubectl, $ns) {
            return \Illuminate\Support\Facades\Process::run("{$kubectl} rollout restart deploy/drive-ocis -n {$ns}")->exitCode() === 0;
        });

        $this->laraKubeInfo("✅ Extension '{$target}' removed. (User document data on S3/volume is preserved).");

        return 0;
    }
}
