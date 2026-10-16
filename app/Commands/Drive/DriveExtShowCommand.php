<?php

namespace App\Commands\Drive;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithOcisExtensions;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\ResolvesToolEnvironment;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class DriveExtShowCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, InteractsWithClusterContext, InteractsWithOcisExtensions, LaraKubeOutput, ReadsCommandOptions, ResolvesToolEnvironment;

    protected $signature = 'drive:ext:show
        {environment? : Target environment — "local" (default) or cloud.}
        {--context=   : Target a specific kube-context}
        {--json       : Emit JSON output}';

    protected $description = 'List installed and available web extensions for Drive (oCIS)';

    public function handle(): int
    {
        $json = (bool) $this->flag('json');

        if ($json) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = ClusterTool::OCIS->namespace();

        $catalog = $this->fetchOcisMarketplaceCatalog();
        $installed = $this->getInstalledOcisExtensions($kubectl, $ns);

        $extensions = [];
        $matchedInstalled = [];

        foreach ($catalog as $item) {
            $parts = explode('.', $item['id']);
            $short = end($parts);
            $cleanShort = str_replace(['web-app-', 'web-extensions-'], '', $short);

            $isInstalled = in_array($item['id'], $installed, true)
                || in_array($short, $installed, true)
                || in_array($cleanShort, $installed, true)
                || in_array(str_replace('web-extensions.', 'web-app-', $item['id']), $installed, true)
                || in_array(str_replace('web-app-', 'web-extensions.', $item['id']), $installed, true);

            if ($isInstalled) {
                $matchedInstalled[] = $item['id'];
                $matchedInstalled[] = $short;
                $matchedInstalled[] = $cleanShort;
                $matchedInstalled[] = str_replace('web-extensions.', 'web-app-', $item['id']);
                $matchedInstalled[] = str_replace('web-app-', 'web-extensions.', $item['id']);
            }

            $extensions[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'description' => $item['description'] ?? '',
                'version' => $item['version'],
                'installed' => $isInstalled,
            ];
        }

        // Add any installed items that were not in catalog
        foreach ($installed as $id) {
            if (! in_array($id, $matchedInstalled, true)) {
                $extensions[] = [
                    'id' => $id,
                    'name' => ucfirst($id),
                    'description' => '',
                    'version' => 'custom',
                    'installed' => true,
                ];
            }
        }

        if ($json) {
            $this->jsonOutput([
                'success' => true,
                'extensions' => $extensions,
            ]);

            return 0;
        }

        $rows = array_map(fn (array $ext): array => [
            $ext['id'],
            $ext['name'],
            $ext['version'],
            $ext['installed'] ? '✅ Installed' : 'Available',
        ], $extensions);

        $this->laraKubeInfo("oCIS Web Extensions Status ('{$env}'):");
        $this->newLine();

        table(
            headers: ['ID', 'Name', 'Version', 'Status'],
            rows: $rows,
        );

        return 0;
    }
}
