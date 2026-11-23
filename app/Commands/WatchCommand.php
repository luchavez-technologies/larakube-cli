<?php

namespace App\Commands;

use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReloadsLongRunningPods;
use Closure;
use FilesystemIterator;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class WatchCommand extends Command
{
    use EmitsJsonOutput, InteractsWithProjectConfig, LaraKubeOutput, ReloadsLongRunningPods;

    protected $signature = 'watch
                            {--environment=local : The environment to target}
                            {--interval=500 : Polling interval in milliseconds}
                            {--quiet-period=1000 : Wait this many milliseconds with no further changes before reloading, so a burst of saves reloads once}
                            {--path=* : Paths to watch (defaults to standard Laravel dirs + composer.lock + .env)}
                            {--json : Print one JSON event per line (watching, changed, reloaded) for Desktop}';

    protected $description = 'Watch project files and gracefully reload long-running pods (Horizon, queues, Octane, Reverb) on change';

    public function handle(): int
    {
        if ($this->option('json')) {
            $this->enableJsonMode();
        }

        if (! $this->isLaraKubeProject()) {
            $this->event('failed', ['error' => 'Not a LaraKube project.']);

            return 1;
        }

        $this->renderHeader();

        $config = $this->getProjectConfig();
        if (! $config) {
            $this->laraKubeError('Could not load .larakube.json.');
            $this->event('failed', ['error' => 'Could not load .larakube.json.']);

            return 1;
        }

        $environment = (string) $this->option('environment');
        $interval = max(100, (int) $this->option('interval')) * 1000;
        $quietPeriod = max(0, (int) $this->option('quiet-period'));

        $paths = $this->resolvePaths($config->getWatchPaths());
        if (empty($paths)) {
            $this->laraKubeError('No watched paths exist in the current directory.');
            $this->event('failed', ['error' => 'No watched paths exist in the project.']);

            return 1;
        }

        $targets = array_keys(self::reloadTargets($config));

        $this->laraKubeInfo('Watching: '.implode(', ', $paths));
        if ($targets === []) {
            $this->laraKubeWarn('Nothing to reload yet — no Octane/Horizon/queues/Reverb in this project. Changes are only reported.');
        }
        $this->line('  <fg=gray>Press Ctrl+C to stop.</>');
        $this->newLine();
        $this->event('watching', ['environment' => $environment, 'paths' => $paths, 'targets' => $targets]);

        $snapshot = fn (): array => static::snapshot($paths);
        $previous = $snapshot();

        while (true) {
            Sleep::usleep($interval);

            $current = $snapshot();
            if ($current === $previous) {
                continue;
            }

            [$files, $previous] = static::settle($snapshot, $previous, $current, $interval, $quietPeriod);

            $this->laraKubeInfo('📝 '.static::describeChange($files).' changed. Reloading…');
            $this->event('changed', ['files' => $files]);

            if ($targets === []) {
                continue;
            }

            $started = now();
            $results = $this->reloadLongRunningPods($config, $environment);
            $this->event('reloaded', [
                'targets' => array_column($results, 'target'),
                'results' => $results,
                'ok' => ! in_array(false, array_column($results, 'ok'), true),
                'ms' => (int) $started->diffInMilliseconds(now()),
            ]);
            $this->newLine();
        }
    }

    /**
     * Keep polling until nothing has changed for `$quietPeriod` ms, so saving
     * several files (or a branch switch) reloads once. Returns every file that
     * changed since `$previous` and the settled snapshot.
     *
     * @param  Closure(): array<string, int>  $snapshot
     * @param  array<string, int>  $previous
     * @param  array<string, int>  $current
     * @return array{0: list<string>, 1: array<string, int>}
     */
    public static function settle(Closure $snapshot, array $previous, array $current, int $intervalMicroseconds, int $quietPeriod): array
    {
        $quietSince = now();

        while ($quietSince->diffInMilliseconds(now()) < $quietPeriod) {
            Sleep::usleep($intervalMicroseconds);

            $next = $snapshot();
            if ($next !== $current) {
                $current = $next;
                $quietSince = now();
            }
        }

        return [static::changedFiles($previous, $current), $current];
    }

    /**
     * Files added, removed or modified between two snapshots, sorted.
     *
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     * @return list<string>
     */
    public static function changedFiles(array $before, array $after): array
    {
        $changed = array_keys(array_diff_assoc($after, $before) + array_diff_key($before, $after));
        sort($changed);

        return $changed;
    }

    /**
     * @param  list<string>  $files
     */
    public static function describeChange(array $files): string
    {
        return match (count($files)) {
            0 => 'Nothing',
            1 => $files[0],
            default => $files[0].' and '.(count($files) - 1).' more',
        };
    }

    /**
     * An mtime fingerprint of every watched file.
     *
     * @param  array<int, string>  $paths
     */
    public static function computeHash(array $paths, ?string $baseDir = null): string
    {
        return md5(serialize(static::snapshot($paths, $baseDir)));
    }

    /**
     * Every watched file's mtime, keyed by its path relative to the project
     * (recursive for directories). Skips bootstrap/cache, which the reload
     * itself rewrites, and OS/editor temp files.
     *
     * @param  array<int, string>  $paths
     * @return array<string, int>
     */
    public static function snapshot(array $paths, ?string $baseDir = null): array
    {
        $entries = [];
        $cwd = $baseDir ?? (string) getcwd();

        foreach ($paths as $path) {
            $absolute = $cwd.DIRECTORY_SEPARATOR.$path;

            if (is_file($absolute)) {
                $entries[$path] = @filemtime($absolute) ?: 0;

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iter as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $pathname = $file->getPathname();
                $basename = $file->getBasename();

                if (str_contains($pathname, DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache')) {
                    continue;
                }

                if ($basename === '.DS_Store' || str_starts_with($basename, '.') || str_ends_with($basename, '~') || str_ends_with($basename, '.swp') || str_ends_with($basename, '.tmp')) {
                    continue;
                }

                $entries[substr($pathname, strlen($cwd) + 1)] = $file->getMTime();
            }
        }

        ksort($entries);

        return $entries;
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

    /**
     * One machine-readable event line under --json; nothing otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    private function event(string $event, array $data = []): void
    {
        if ($this->option('json')) {
            $this->jsonOutput(['event' => $event, 'at' => now()->toIso8601String(), ...$data]);
        }
    }
}
