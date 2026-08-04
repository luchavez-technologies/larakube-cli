<?php

namespace App\Commands\Drive;

use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithOcisExtensions;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class DriveExtShowCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithOcisExtensions, LaraKubeOutput, ResolvesToolEnvironment;

    protected $signature = 'drive:ext:show
        {environment? : Target environment — "local" (default) or cloud.}
        {--context=   : Target a specific kube-context}';

    protected $description = 'List installed and available web extensions for Drive (oCIS)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->contextKubectl($context);
        $ns = ClusterTool::DRIVE->namespace();

        $catalog = $this->fetchOcisMarketplaceCatalog();
        $installed = $this->getInstalledOcisExtensions($kubectl, $ns);

        $rows = [];
        $matchedInstalled = [];

        foreach ($catalog as $item) {
            $parts = explode('.', $item['id']);
            $short = end($parts);
            $isInstalled = in_array($item['id'], $installed, true) || in_array($short, $installed, true);

            if ($isInstalled) {
                $matchedInstalled[] = $item['id'];
                $matchedInstalled[] = $short;
            }

            $rows[] = [
                $item['id'],
                $item['name'],
                $item['version'],
                $isInstalled ? '✅ Installed' : 'Available',
            ];
        }

        // Add any installed items that were not in catalog
        foreach ($installed as $id) {
            if (! in_array($id, $matchedInstalled, true)) {
                $rows[] = [
                    $id,
                    ucfirst($id),
                    'custom',
                    '✅ Installed',
                ];
            }
        }

        $this->laraKubeInfo("oCIS Web Extensions Status ('{$env}'):");
        $this->newLine();

        table(
            headers: ['ID', 'Name', 'Version', 'Status'],
            rows: $rows,
        );

        return 0;
    }
}
