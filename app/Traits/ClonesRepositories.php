<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;
use RuntimeException;

trait ClonesRepositories
{
    use StreamsProcessOutput;

    /**
     * Resolve a raw repo argument to a full git-clonable URL.
     *
     * Accepts:
     *   - Full HTTPS URL  → used as-is  (https://github.com/…)
     *   - Full SSH URL    → used as-is  (git@github.com:…)
     *   - user/repo       → resolved via $provider (default: github)
     *
     * @param  'github'|'gitlab'|'bitbucket'  $provider
     */
    protected function resolveRepoUrl(string $repo, string $provider = 'github'): string
    {
        if (str_starts_with($repo, 'http://') || str_starts_with($repo, 'https://') || str_starts_with($repo, 'git@')) {
            return $repo;
        }

        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            $host = match ($provider) {
                'gitlab' => 'gitlab.com',
                'bitbucket' => 'bitbucket.org',
                default => 'github.com',
            };

            return "https://{$host}/{$repo}.git";
        }

        return $repo;
    }

    /**
     * Derive the target directory name from a repo URL or explicit argument.
     * Strips .git suffix and returns just the last path segment.
     */
    protected function deriveDirectoryName(string $repoUrl): string
    {
        $base = basename(rtrim($repoUrl, '/'));

        return preg_replace('/\.git$/', '', $base);
    }

    /**
     * Run `git clone` and return the exit code.
     * Streams output live (passthru), so the user sees progress.
     */
    protected function runGitClone(string $url, string $directory, ?string $branch = null): int
    {
        $cmd = 'git clone '.escapeshellarg($url);

        if ($branch !== null && $branch !== '') {
            $cmd .= ' --branch '.escapeshellarg($branch);
        }

        $cmd .= ' '.escapeshellarg($directory);

        return $this->runStreaming($cmd);
    }

    /**
     * Run `composer install` in $workDir, streaming output.
     * Falls back to Docker if composer is not available on PATH.
     */
    protected function runComposerInstall(string $workDir): int
    {
        $composer = $this->resolveComposerCommand($workDir);

        return $this->runStreaming("{$composer} install --no-interaction");
    }

    protected function resolveComposerCommand(string $workDir): string
    {
        $candidates = array_filter([
            trim(Process::run('command -v composer')->output()),
            '/usr/local/bin/composer',
            '/opt/homebrew/bin/composer',
        ]);

        foreach ($candidates as $path) {
            if ($path !== '' && @is_executable($path)) {
                return $path;
            }
        }

        // Docker fallback (same philosophy as gh)
        $wd = escapeshellarg($workDir);

        return "docker run --rm -v {$wd}:/app -w /app composer:latest";
    }

    /**
     * Copy .env.example → .env and generate an app key.
     * Returns 'copied' or 'exists'. Throws if .env.example is missing.
     *
     * @throws RuntimeException when .env.example does not exist
     */
    protected function bootstrapDotEnv(string $workDir): string
    {
        $envPath = $workDir.'/.env';
        $examplePath = $workDir.'/.env.example';

        if (! file_exists($examplePath)) {
            throw new RuntimeException("No .env.example found in {$workDir}. Cannot bootstrap .env — create one and re-run.");
        }

        if (file_exists($envPath)) {
            return 'exists';
        }

        copy($examplePath, $envPath);

        // Generate app key (if artisan exists)
        if (file_exists($workDir.'/artisan')) {
            Process::run('php '.escapeshellarg($workDir.'/artisan').' key:generate --ansi');
        }

        return 'copied';
    }

    /**
     * Patch key environment variables in the .env file.
     * Only rewrites lines whose key matches; adds the key if not present.
     *
     * @param  array<string, string>  $vars
     */
    protected function patchDotEnv(string $workDir, array $vars): void
    {
        $envPath = $workDir.'/.env';

        if (! file_exists($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        $updated = [];

        foreach ($lines as $line) {
            $patched = false;

            foreach ($vars as $key => $value) {
                if (str_starts_with($line, "{$key}=") || $line === $key) {
                    $updated[] = "{$key}={$value}";
                    unset($vars[$key]);
                    $patched = true;
                    break;
                }
            }

            if (! $patched) {
                $updated[] = $line;
            }
        }

        // Append any keys that weren't already present
        foreach ($vars as $key => $value) {
            $updated[] = "{$key}={$value}";
        }

        file_put_contents($envPath, implode("\n", $updated)."\n");
    }
}
