<?php

namespace App\Commands\Workspace;

use App\Enums\WorkspaceRuntime;
use App\Services\Workspace\WorkspaceSpec;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/** Every workspace image there is to publish: a runtime, a version and the image it is built on. */
class WorkspaceImagesCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'workspace:images
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List the workspace images, for the pipeline that publishes them';

    public function handle(): int
    {
        $images = [];

        foreach (WorkspaceRuntime::cases() as $runtime) {
            foreach ($runtime->versions() as $version) {
                $images[] = ['runtime' => $runtime->value, 'version' => $version, 'base' => $runtime->baseImage($version)];
            }
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'codeServer' => WorkspaceSpec::CODE_SERVER_VERSION, 'images' => $images]);

            return 0;
        }

        table(headers: ['Runtime', 'Version', 'Built on'], rows: array_map(fn (array $i): array => [$i['runtime'], $i['version'], $i['base']], $images));

        return 0;
    }
}
