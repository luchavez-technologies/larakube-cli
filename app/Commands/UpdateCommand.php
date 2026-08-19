<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;
use Exception;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class UpdateCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'update {--canary : Update to the latest canary (bleeding-edge, unstable) build from main}';

    protected $description = 'Update the LaraKube CLI to the latest version';

    public function handle(): int
    {
        $this->renderHeader();

        if ($this->isHomebrewInstall()) {
            return $this->deferToHomebrew();
        }

        return $this->option('canary')
            ? $this->updateToCanary()
            : $this->updateToLatestRelease();
    }

    /**
     * Homebrew-managed installs (stable or canary formula) must be updated via
     * `brew`, not by self-replacing the binary — Homebrew tracks the Cellar
     * keg/version itself, and a self-swap would silently drift out of sync
     * with what it thinks is installed (a later `brew upgrade` could then
     * overwrite the swap back). Detected by the running binary living under a
     * Homebrew Cellar, true for every prefix Homebrew uses (/usr/local on
     * Intel, /opt/homebrew on Apple Silicon, /home/linuxbrew/.linuxbrew on
     * Linuxbrew) — checking the prefix itself would miss some of those.
     */
    protected function deferToHomebrew(): int
    {
        $this->laraKubeInfo('This is a Homebrew-managed install — update via Homebrew instead:');
        $this->newLine();
        $this->line('  <fg=yellow>brew upgrade larakube</>          <fg=gray>latest stable release</>');
        $this->line('  <fg=yellow>brew reinstall larakube-canary</> <fg=gray>latest canary (bleeding-edge) build</>');
        $this->newLine();
        $this->line('  <fg=gray>(Install the canary formula once with: brew install luchavez-technologies/larakube/larakube-canary)</>');

        return 0;
    }

    protected function updateToLatestRelease(): int
    {
        $currentVersion = config('app.version');
        $this->laraKubeInfo("Current version: <fg=yellow>$currentVersion</>");

        $this->laraKubeInfo('Checking for latest version...');

        $response = Http::withHeaders(['User-Agent' => 'LaraKube-CLI'])
            ->get('https://api.github.com/repos/luchavez-technologies/larakube-cli/releases/latest');

        if ($response->failed()) {
            $this->laraKubeError('Failed to fetch the latest version from GitHub.');

            return 1;
        }

        $latestVersion = $response->json('tag_name');

        if ($latestVersion === $currentVersion || $currentVersion === 'unreleased') {
            $this->laraKubeInfo('✅ You are already using the latest version!');

            return 0;
        }

        $this->laraKubeInfo("A new version is available: <fg=green>$latestVersion</>");

        if (! $this->confirm('Do you want to update now?', true)) {
            return 0;
        }

        return $this->downloadAndInstall($latestVersion);
    }

    /**
     * Canary builds are the tip of main, republished under the same GitHub
     * Release tag ("canary") on every push to main — there's no version to
     * diff against, so this always re-downloads and re-installs on
     * confirmation rather than checking whether anything changed first.
     */
    protected function updateToCanary(): int
    {
        $this->laraKubeWarn('⚠ Canary builds are unstable, bleeding-edge builds from the tip of main — they may be broken.');

        if (! $this->confirm('Update to the latest canary build now?', false)) {
            return 0;
        }

        $response = Http::withHeaders(['User-Agent' => 'LaraKube-CLI'])
            ->get('https://api.github.com/repos/luchavez-technologies/larakube-cli/releases/tags/canary');

        if ($response->failed()) {
            $this->laraKubeError('Failed to fetch the canary release from GitHub.');

            return 1;
        }

        return $this->downloadAndInstall('canary');
    }

    protected function downloadAndInstall(string $version): int
    {
        // 1. Detect OS and Architecture
        $os = strtolower(PHP_OS_FAMILY) === 'darwin' ? 'mac' : 'linux';
        $machine = php_uname('m');

        $arch = match ($machine) {
            'x86_64' => 'x64',
            'arm64', 'aarch64' => 'arm',
            default => null,
        };

        if (! $arch) {
            $this->laraKubeError("Unsupported architecture: $machine");

            return 1;
        }

        $binaryName = "larakube-$os-$arch";
        $downloadUrl = "https://github.com/luchavez-technologies/larakube-cli/releases/download/$version/$binaryName";

        $this->laraKubeInfo("Downloading $binaryName for $os ($arch)...");

        // A hardcoded /tmp path would let any local user race it with a
        // symlink before `sudo mv` moves it into place. tempnam() used to
        // defend against this by picking an OS-unpredictable name; a 0700
        // directory (no group/other execute bit — no traversal, so nothing
        // to symlink over) plus a cryptographically random name (not
        // TemporaryDirectory's default mt_rand()+microtime(), which a local
        // attacker could feasibly predict) gives the same guarantee.
        $temporaryDirectory = (new TemporaryDirectory)
            ->name(bin2hex(random_bytes(16)))
            ->permission(0700)
            ->deleteWhenDestroyed()
            ->create();
        $tempPath = $temporaryDirectory->path().'/larakube-update';

        try {
            $binaryContent = file_get_contents($downloadUrl);
            if ($binaryContent === false) {
                throw new Exception('Download failed.');
            }
            file_put_contents($tempPath, $binaryContent);
        } catch (Exception $e) {
            $this->laraKubeError("Failed to download binary from $downloadUrl");

            return 1;
        }

        $this->laraKubeInfo('🚚 Installing to /usr/local/bin/larakube (requires sudo)...');

        // Atomic swap via sudo
        $installCmd = 'sudo mv '.escapeshellarg($tempPath).' /usr/local/bin/larakube && sudo chmod +x /usr/local/bin/larakube';
        passthru($installCmd, $exitCode);
        $temporaryDirectory->delete();

        if ($exitCode !== 0) {
            $this->laraKubeError('Installation failed. Please check your permissions.');

            return 1;
        }

        $this->laraKubeInfo("✅ LaraKube updated successfully to $version!");

        return 0;
    }

    protected function isHomebrewInstall(): bool
    {
        $binaryPath = $_SERVER['argv'][0] ?? '';
        if ($binaryPath === '') {
            return false;
        }

        $real = realpath($binaryPath) ?: $binaryPath;

        return str_contains($real, '/Cellar/');
    }
}
