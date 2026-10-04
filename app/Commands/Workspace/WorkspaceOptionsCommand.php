<?php

namespace App\Commands\Workspace;

use App\Enums\AppFramework;
use App\Enums\WorkspaceRuntime;
use App\Services\Workspace\WorkspaceSpec;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/** The sizes and defaults a workspace form offers, so GUIs draw them instead of keeping a copy. */
class WorkspaceOptionsCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'workspace:options
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List the sizes and defaults for a workspace';

    public function handle(): int
    {
        $sizes = [];
        foreach (WorkspaceSpec::sizes() as $value => $size) {
            $sizes[] = ['value' => $value, 'label' => $size['label'], 'memory' => $size['memory'], 'cpu' => $size['cpu'], 'storage' => $size['storage']];
        }

        $runtimes = array_map(fn (WorkspaceRuntime $runtime): array => [
            'value' => $runtime->value,
            'label' => $runtime->label(),
            'versions' => $runtime->versions(),
            'defaultVersion' => $runtime->defaultVersion(),
        ], WorkspaceRuntime::cases());

        $frameworks = array_map(fn (AppFramework $framework): array => [
            'value' => $framework->value,
            'label' => $framework->getLabel(),
            'runtime' => $framework->workspaceRuntime()->value,
            'devCommand' => $framework->devCommand(),
            'devPorts' => $framework->devPorts(),
        ], AppFramework::cases());

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'sizes' => $sizes, 'runtimes' => $runtimes, 'frameworks' => $frameworks, 'defaultSize' => WorkspaceSpec::defaultSize(), 'defaultFramework' => AppFramework::LARAVEL->value, 'defaultBranch' => 'main']);

            return 0;
        }

        table(headers: ['Size', 'Memory', 'CPU', 'Disk'], rows: array_map(fn (array $s): array => [$s['value'], $s['memory'], $s['cpu'], $s['storage']], $sizes));
        table(headers: ['Framework', 'Runtime', 'Dev command'], rows: array_map(fn (array $f): array => [$f['value'], $f['runtime'], $f['devCommand']], $frameworks));

        return 0;
    }
}
