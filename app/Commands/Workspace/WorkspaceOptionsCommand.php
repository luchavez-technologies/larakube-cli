<?php

namespace App\Commands\Workspace;

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

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'sizes' => $sizes, 'defaultSize' => WorkspaceSpec::defaultSize(), 'defaultBranch' => 'main']);

            return 0;
        }

        table(headers: ['Size', 'Memory', 'CPU', 'Disk'], rows: array_map(fn (array $s): array => [$s['value'], $s['memory'], $s['cpu'], $s['storage']], $sizes));

        return 0;
    }
}
