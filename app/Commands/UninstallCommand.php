<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class UninstallCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The console command aliases.
     *
     * @var list<string>
     */
    protected $aliases = ['self-uninstall'];

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'uninstall
        {--all   : Remove all companion tools (~/.larakube/bin), cluster data, and shell PATH integration}
        {--purge : Delete the ~/.larakube configuration directory}
        {--force : Force uninstallation without interactive confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Remove the LaraKube CLI application and companion tools from your system';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube CLI Uninstaller');

        // Safety Guard: Don't run inside a container
        if (file_exists('/.dockerenv')) {
            $this->error('  ✖ The "uninstall" command must be run directly on your HOST machine.');

            return 1;
        }

        $all = (bool) $this->option('all');
        $purge = $all || (bool) $this->option('purge');
        $force = (bool) $this->option('force');

        $binaryPath = $this->resolveBinaryPath();

        if (! $force) {
            if ($binaryPath !== null) {
                $this->warn("  ⚠ This will delete the LaraKube CLI binary from: {$binaryPath}");
            }
            if ($all) {
                $this->warn('  ⚠ This will remove all companion tools from ~/.larakube/bin and clean shell PATH configuration.');
            }
            if ($purge) {
                $this->warn('  ⚠ This will completely purge ~/.larakube credentials, cluster state, and configs.');
            }
            $this->warn("  ⚠ Run 'larakube trust:remove' before uninstalling if you wish to clean up system SSL certificates.");
            $this->newLine();

            if (! confirm('Are you sure you want to uninstall the LaraKube CLI?', false)) {
                $this->laraKubeInfo('Uninstall cancelled.');

                return 0;
            }
        }

        // 1. Clean shell profile PATH if --all
        if ($all) {
            $cleaned = $this->cleanShellProfiles();
            if ($cleaned !== []) {
                $this->info('  ✔ Removed LaraKube bin directory from shell profile(s): '.implode(', ', $cleaned));
            }
        }

        // 2. Remove companion tools in ~/.larakube/bin if --all (and not already purging everything)
        $binDir = home_path('.larakube/bin');
        if ($all && ! $purge && is_dir($binDir)) {
            $this->withSpin('Removing companion tools in ~/.larakube/bin...', function () use ($binDir): bool {
                $this->deleteDirectory($binDir);

                return true;
            });
        }

        // 3. Purge ~/.larakube if requested
        $laraKubeDir = home_path('.larakube');
        if ($purge && is_dir($laraKubeDir)) {
            $this->withSpin('Purging ~/.larakube directory...', function () use ($laraKubeDir): bool {
                $this->deleteDirectory($laraKubeDir);

                return true;
            });
        }

        // 4. Remove LaraKube binary
        $this->removeBinary($binaryPath);

        $this->laraKubeInfo('LaraKube CLI has been removed from your system.');

        return 0;
    }

    /**
     * Remove ~/.larakube/bin export from user shell profiles.
     *
     * @return list<string>
     */
    public function cleanShellProfiles(?string $home = null): array
    {
        $home = $home ?? home_path();
        $candidates = [
            "{$home}/.zshrc",
            "{$home}/.bashrc",
            "{$home}/.bash_profile",
            "{$home}/.profile",
        ];
        $exportLine = 'export PATH="$HOME/.larakube/bin:$PATH"';
        $cleaned = [];

        foreach ($candidates as $candidate) {
            if (! is_file($candidate)) {
                continue;
            }

            $content = (string) file_get_contents($candidate);
            if (str_contains($content, '.larakube/bin')) {
                // Remove with leading comment if present
                $pattern = '/\n?# Added by LaraKube(?: Desktop)?\n'.preg_quote($exportLine, '/').'\n?/';
                $newContent = preg_replace($pattern, "\n", $content);

                // Fallback for standalone export line
                if (is_string($newContent) && str_contains($newContent, $exportLine)) {
                    $newContent = str_replace($exportLine."\n", '', $newContent);
                    $newContent = str_replace($exportLine, '', $newContent);
                }

                // Remove any remaining generic lines referencing .larakube/bin in PATH
                if (is_string($newContent) && str_contains($newContent, '.larakube/bin')) {
                    $newContent = preg_replace('/[^\n]*\.larakube\/bin[^\n]*\n?/', '', $newContent);
                }

                file_put_contents($candidate, trim((string) $newContent)."\n");
                $cleaned[] = $candidate;
            }
        }

        return $cleaned;
    }

    /**
     * Recursively delete a directory.
     */
    public function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Resolve the active binary path.
     */
    protected function resolveBinaryPath(): ?string
    {
        $argv0 = $_SERVER['argv'][0] ?? '';

        if (basename($argv0) === 'larakube' && is_file($argv0)) {
            return $argv0;
        }

        $managed = home_path('.larakube/bin/larakube');
        if (is_file($managed)) {
            return $managed;
        }

        if (is_file('/usr/local/bin/larakube')) {
            return '/usr/local/bin/larakube';
        }

        return null;
    }

    /**
     * Remove the active binary.
     */
    protected function removeBinary(?string $binaryPath): void
    {
        if ($binaryPath === null || ! is_file($binaryPath)) {
            return;
        }

        // Never delete binary runners during unit or feature testing
        if (app()->runningUnitTests()) {
            return;
        }

        $this->withSpin('Removing LaraKube CLI binary...', function () use ($binaryPath): bool {
            if (is_writable($binaryPath)) {
                @unlink($binaryPath);
            } else {
                exec('sudo rm -f '.escapeshellarg($binaryPath));
            }

            return true;
        });
    }
}
