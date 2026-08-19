<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use FilesystemIterator;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class WatchCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'watch
                            {--environment=local : The environment to target}
                            {--interval=500 : Polling interval in milliseconds}
                            {--path=* : Paths to watch (defaults to standard Laravel dirs + composer.lock + .env)}';

    protected $description = 'Watch project files and trigger larakube reload on change';

    public function handle(): int
    {
        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $this->renderHeader();

        $config = $this->getProjectConfig();
        if (! $config) {
            $this->laraKubeError('Could not load .larakube.json.');

            return 1;
        }

        $environment = $this->option('environment');
        $interval = max(100, (int) $this->option('interval')) * 1000;

        $paths = $this->resolvePaths($config->getWatchPaths());
        if (empty($paths)) {
            $this->laraKubeError('No watched paths exist in the current directory.');

            return 1;
        }

        $this->laraKubeInfo('Watching: '.implode(', ', $paths));
        $this->line('  <fg=gray>Press Ctrl+C to stop.</>');
        $this->newLine();

        $previous = static::computeHash($paths);

        while (true) {
            Sleep::usleep($interval);

            $current = static::computeHash($paths);
            if ($current === $previous) {
                continue;
            }

            $previous = $current;
            $this->laraKubeInfo('📝 Change detected. Reloading…');
            $this->call('reload', ['--environment' => $environment]);
            $this->newLine();
        }
    }

    /**
     * Compute an mtime fingerprint of every file under each path (recursive for dirs).
     *
     * @param  array<int, string>  $paths
     */
    public static function computeHash(array $paths, ?string $baseDir = null): string
    {
        $entries = [];
        $cwd = $baseDir ?? getcwd();

        foreach ($paths as $path) {
            $absolute = $cwd.DIRECTORY_SEPARATOR.$path;

            if (is_file($absolute)) {
                $entries[$absolute] = @filemtime($absolute) ?: 0;

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $entries[$file->getPathname()] = $file->getMTime();
                }
            }
        }

        ksort($entries);

        return md5(serialize($entries));
    }

    /**
     * @param  array<int, string>  $blueprintPaths
     * @return array<int, string>
     */
    protected function resolvePaths(array $blueprintPaths): array
    {
        $cwd = getcwd();
        $requested = $this->option('path') ?: $blueprintPaths;

        return array_values(array_filter(
            $requested,
            fn (string $p) => file_exists($cwd.DIRECTORY_SEPARATOR.$p),
        ));
    }
}
