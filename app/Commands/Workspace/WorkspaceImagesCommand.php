<?php

namespace App\Commands\Workspace;

use App\Enums\WorkspaceRuntime;
use App\Services\Scaffolding\BuilderImage;
use App\Services\Workspace\WorkspaceSpec;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/** The images this CLI pulls: workspace images and the builders `new` runs in, each with its image reference. */
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

        $builders = [
            ...array_map(fn (string $version): array => ['runtime' => 'php', 'version' => $version, 'image' => (string) BuilderImage::php($version)], BuilderImage::PHP_VERSIONS),
            ...array_map(fn (string $version): array => ['runtime' => 'python', 'version' => $version, 'image' => (string) BuilderImage::python($version)], BuilderImage::PYTHON_VERSIONS),
        ];

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'images' => $images, 'builders' => $builders]);

            return 0;
        }

        table(headers: ['Runtime', 'Version', 'Image'], rows: array_map(fn (array $i): array => [$i['runtime'], $i['version'], $i['image']], [...$images, ...$builders]));

        return 0;
    }
}
