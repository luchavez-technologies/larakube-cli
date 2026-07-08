<?php

namespace App\Commands\Bundle;

use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class BundleUnzipCommand extends Command
{
    use LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'bundle:unzip 
                            {path? : Path to the .tar.gz bundle to extract}
                            {--delete : Delete the archive after extracting}';

    protected $description = 'Extract an assembled air-gapped bundle archive';

    public function handle(): int
    {
        $this->renderHeader();

        $path = (string) $this->argument('path');

        if ($path === '') {
            $distDir = getcwd().'/dist';
            if (! is_dir($distDir)) {
                $this->laraKubeError("No 'dist' directory found. Please provide the exact path to the archive.");

                return 1;
            }

            $archives = glob($distDir.'/*.tar.gz');

            if (empty($archives)) {
                $this->laraKubeError("No valid .tar.gz bundles found in the 'dist' directory.");

                return 1;
            }

            if (count($archives) === 1) {
                $path = $archives[0];
            } else {
                $options = [];
                foreach ($archives as $archive) {
                    $options[] = basename($archive);
                }

                $selected = select(
                    label: 'Which bundle do you want to extract?',
                    options: $options,
                );

                $path = "dist/{$selected}";
            }
        }

        if (! file_exists($path)) {
            $this->laraKubeError("File '{$path}' does not exist.");

            return 1;
        }

        $realPath = realpath($path);
        $baseDir = dirname($realPath);

        $this->laraKubeInfo('Extracting '.basename($realPath).'...');

        $tarCode = $this->runStreaming('tar -xzf '.escapeshellarg($realPath).' -C '.escapeshellarg($baseDir));

        if ($tarCode === 0) {
            $this->laraKubeInfo('✅ Bundle extracted successfully!');
            if ($this->option('delete')) {
                Process::run('rm -f '.escapeshellarg($realPath));
                $this->laraKubeInfo('Deleted archive file.');
            }
        } else {
            $this->laraKubeError('Failed to extract the bundle.');

            return 1;
        }

        return 0;
    }
}
