<?php

namespace App\Commands\Drive;

use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithOcisExtensions;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\multiselect;

use LaravelZero\Framework\Commands\Command;

class DriveExtAddCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithOcisExtensions, LaraKubeOutput, ResolvesToolEnvironment;

    protected $signature = 'drive:ext:add
        {environment? : Target environment — "local" (default) or cloud.}
        {--context=   : Target a specific kube-context}
        {--extension= : Specific web extension ID or URL to install}';

    protected $description = 'Install and enable web extensions for Drive (oCIS)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->contextKubectl($context);
        $ns = ClusterTool::DRIVE->namespace();

        $this->ensureOcisWebAssetAppsPath($kubectl, $ns);

        $catalog = $this->fetchOcisMarketplaceCatalog();
        $installed = $this->getInstalledOcisExtensions($kubectl, $ns);

        $specified = (string) $this->option('extension');
        $selected = [];

        if ($specified !== '') {
            $selected = [$specified];
        } else {
            $options = [];
            $defaultSelected = [];

            foreach ($catalog as $item) {
                $parts = explode('.', $item['id']);
                $short = end($parts);
                $isInstalled = in_array($item['id'], $installed, true) || in_array($short, $installed, true);

                if ($isInstalled) {
                    $defaultSelected[] = $item['id'];
                }

                $status = $isInstalled ? ' [Installed]' : '';
                $options[$item['id']] = "{$item['name']} ({$item['version']}) — {$item['description']}{$status}";
            }

            $selected = multiselect(
                label: 'Select oCIS Web Extensions to install:',
                options: $options,
                default: $defaultSelected,
                hint: 'Use spacebar to select extensions, Enter to confirm.',
            );
        }

        if ($selected === []) {
            $this->laraKubeInfo('No extensions selected.');

            return 0;
        }

        foreach ($selected as $ext) {
            $this->withSpin("Installing oCIS extension '{$ext}'...", function () use ($kubectl, $ns, $ext) {
                return $this->installOcisExtension($kubectl, $ns, $ext);
            });
            $this->laraKubeInfo("✅ Web extension '{$ext}' installed.");
        }

        $this->withSpin('Restarting Drive (oCIS) deployment to activate extensions...', function () use ($kubectl, $ns) {
            return Process::run("{$kubectl} rollout restart deploy/drive-ocis -n {$ns}")->exitCode() === 0;
        });

        $this->newLine();
        $this->laraKubeInfo('Refresh your oCIS browser window to view newly activated extensions.');

        return 0;
    }
}
