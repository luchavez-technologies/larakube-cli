<?php

namespace App\Commands\Bundle;

use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class BundleZipCommand extends Command
{
    use LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'bundle:zip 
                            {path? : Path to the bundle directory to compress}
                            {--output= : Custom name for the output file (e.g. client-a)}
                            {--delete : Delete the uncompressed folder after zipping}';

    protected $description = 'Compress an assembled air-gapped bundle into a .tar.gz archive';

    public function handle(): int
    {
        $this->renderHeader();

        $path = $this->argument('path');

        if (! $path) {
            $distDir = getcwd().'/dist';
            if (! is_dir($distDir)) {
                $this->laraKubeError("No 'dist' directory found. Have you run 'bundle:build' yet?");

                return 1;
            }

            $directories = glob($distDir.'/*', GLOB_ONLYDIR);
            $bundles = [];

            foreach ($directories as $dir) {
                if (file_exists($dir.'/bundle.json')) {
                    $bundles[] = basename($dir);
                }
            }

            if (empty($bundles)) {
                $this->laraKubeError("No valid bundles found in the 'dist' directory.");

                return 1;
            }

            if (count($bundles) === 1) {
                $selected = $bundles[0];
            } else {
                $selected = select(
                    label: 'Which bundle do you want to compress?',
                    options: $bundles,
                );
            }

            $path = "dist/{$selected}";
        }

        if (! is_dir($path)) {
            $this->laraKubeError("Directory '{$path}' does not exist.");

            return 1;
        }

        if (! file_exists($path.'/bundle.json')) {
            $this->laraKubeError("Directory '{$path}' does not appear to be a valid LaraKube bundle (missing bundle.json).");

            return 1;
        }

        $realPath = realpath($path);
        $baseDir = dirname($realPath);
        $folderName = basename($realPath);

        $outputFile = (string) $this->option('output');
        if ($outputFile !== '') {
            if (! str_ends_with($outputFile, '.tar.gz')) {
                $outputFile .= '.tar.gz';
            }
            // If they provided an absolute path, use it. Otherwise, place it in the same directory.
            if (str_starts_with($outputFile, '/')) {
                $tarFile = $outputFile;
            } else {
                $tarFile = $baseDir.'/'.basename($outputFile);
            }
        } else {
            $tarFile = $realPath.'.tar.gz';
        }

        $this->laraKubeInfo("Compressing {$folderName} into a .tar.gz archive...");

        $tarCode = $this->runStreaming('tar -czf '.escapeshellarg($tarFile).' -C '.escapeshellarg($baseDir).' '.escapeshellarg($folderName));

        if ($tarCode === 0) {
            $this->laraKubeInfo('✅ Bundle compressed successfully: '.$tarFile);
            if ($this->option('delete')) {
                Process::run('rm -rf '.escapeshellarg($realPath));
                $this->laraKubeInfo('Deleted uncompressed folder.');
            } else {
                $this->laraKubeInfo('You can safely delete the uncompressed folder manually or use --delete next time.');
            }
        } else {
            $this->laraKubeError('Failed to compress the bundle.');

            return 1;
        }

        return 0;
    }
}
