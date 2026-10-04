<?php

namespace App\Commands\Workspace;

use App\Enums\WorkspaceRuntime;
use App\Services\Workspace\WorkspaceSpec;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/** The workspace images this CLI pulls: a runtime, a version and the image reference. */
class WorkspaceImagesCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'workspace:images
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List the workspace images this CLI pulls';

    public function handle(): int
    {
        $images = [];

        foreach (WorkspaceRuntime::cases() as $runtime) {
            if (! $runtime->published()) {
                continue;
            }

            foreach ($runtime->versions() as $version) {
                $images[] = ['runtime' => $runtime->value, 'version' => $version, 'image' => WorkspaceSpec::image($runtime, $version)];
            }
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'images' => $images]);

            return 0;
        }

        table(headers: ['Runtime', 'Version', 'Image'], rows: array_map(fn (array $i): array => [$i['runtime'], $i['version'], $i['image']], $images));

        return 0;
    }
}
