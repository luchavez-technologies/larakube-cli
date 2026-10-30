<?php

namespace App\Commands;

use App\Services\WslBridge;
use App\Support\Os;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Exception;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class UpdateCommand extends Command
{
    use LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'update
        {--canary : Update to the latest canary (bleeding-edge, unstable) build from develop}
        {--yes : Update without asking, for a caller with no terminal (LaraKube Desktop)}';

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

        $repo = $this->getGithubRepository();

        $response = Http::withHeaders(['User-Agent' => 'LaraKube-CLI'])
            ->get($this->getGithubApiUrl()."/repos/{$repo}/releases/latest");

        if ($response->failed()) {
            $this->laraKubeError('Failed to fetch the latest version from the GitHub release server.');

            return 1;
        }

        $latestVersion = $response->json('tag_name');

        if ($latestVersion === $currentVersion || $currentVersion === 'unreleased') {
            $this->laraKubeInfo('✅ You are already using the latest version!');

            return 0;
        }

        $this->laraKubeInfo("A new version is available: <fg=green>$latestVersion</>");

        if (! $this->option('yes') && ! $this->confirm('Do you want to update now?', true)) {
            return 0;
        }

        return $this->downloadAndInstall($latestVersion);
    }

    /**
     * Canary builds are the tip of develop, republished under the same GitHub
     * Release tag ("canary") on every push to develop — there's no version to
     * diff against, so this always re-downloads and re-installs on
     * confirmation rather than checking whether anything changed first.
     */
    protected function updateToCanary(): int
    {
        $this->laraKubeWarn('⚠ Canary builds are unstable, bleeding-edge builds from the tip of develop — they may be broken.');

        if (! $this->option('yes') && ! $this->confirm('Update to the latest canary build now?', false)) {
            return 0;
        }

        $repo = $this->getGithubRepository();

        $response = Http::withHeaders(['User-Agent' => 'LaraKube-CLI'])
            ->get($this->getGithubApiUrl()."/repos/{$repo}/releases/tags/canary");

        if ($response->failed()) {
            $this->laraKubeError('Failed to fetch the canary release from the GitHub release server.');

            return 1;
        }

        return $this->downloadAndInstall('canary');
    }

    protected function downloadAndInstall(string $version): int
    {
        // 1. Detect OS and Architecture
        $os = match (strtolower(Os::family())) {
            'darwin' => 'mac',
            'windows' => 'windows',
            default => 'linux',
        };
        $machine = Os::machine();

        // Windows's php_uname('m') returns "AMD64"/"ARM64" (uppercase),
        // unlike Darwin/Linux's lowercase "x86_64"/"arm64" — confirmed live
        // (2026-10-30): this match was case-sensitive, so every Windows
        // update failed with "Unsupported architecture: AMD64" regardless
        // of the real architecture.
        $arch = match (strtolower($machine)) {
            'x86_64', 'amd64' => 'x64',
            'arm64', 'aarch64' => 'arm',
            default => null,
        };

        if (! $arch) {
            $this->laraKubeError("Unsupported architecture: $machine");

            return 1;
        }

        $repo = $this->getGithubRepository();
        $ext = $os === 'windows' ? '.exe' : '';
        $binaryName = "larakube-$os-$arch$ext";
        $downloadUrl = $this->getGithubUrl()."/{$repo}/releases/download/$version/$binaryName";

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
            // Streamed straight to disk: the binary is tens of megabytes, more than the CLI's own memory limit holds twice.
            // retry(): GitHub's release CDN occasionally resets the connection
            // mid-download (confirmed live, 2026-10-30: "The request was aborted:
            // The connection was closed unexpectedly" on a real Windows machine)
            // — almost always transient, worth a couple of retries before failing.
            $download = Http::withHeaders(['User-Agent' => 'LaraKube-CLI'])->timeout(600)->retry(3, 2000)->sink($tempPath)->get($downloadUrl);

            if ($download->failed() || ! is_file($tempPath) || filesize($tempPath) < 1024) {
                throw new Exception('Download failed.');
            }
        } catch (Exception $e) {
            $this->laraKubeError("Failed to download binary from $downloadUrl");

            return 1;
        }

        if ($os === 'windows') {
            $userProfile = (string) (getenv('USERPROFILE') ?: (getenv('HOMEDRIVE') && getenv('HOMEPATH') ? getenv('HOMEDRIVE').getenv('HOMEPATH') : ''));
            $targetDir = $userProfile !== '' ? $userProfile.'/.larakube/bin' : '';
            $target = $targetDir !== '' ? "{$targetDir}/larakube.exe" : (realpath($_SERVER['argv'][0]) ?: 'larakube.exe');

            if ($targetDir !== '' && ! is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }

            $this->laraKubeInfo("🚚 Installing to {$target}...");

            $oldExe = $target.'.old';
            if (file_exists($oldExe)) {
                @unlink($oldExe);
            }
            if (file_exists($target)) {
                @rename($target, $oldExe);
            }

            if (! @copy($tempPath, $target)) {
                if (file_exists($oldExe)) {
                    @rename($oldExe, $target);
                }
                $temporaryDirectory->delete();
                $this->laraKubeError("Installation failed. Could not copy new binary to {$target}.");

                return 1;
            }

            if (file_exists($oldExe)) {
                @unlink($oldExe);
            }

            $this->laraKubeInfo("✅ LaraKube Windows binary updated successfully to $version!");

            $bridge = app(WslBridge::class);

            // Only bother keeping the WSL2 Linux-binary cache in lockstep if a
            // distro actually exists to sync it into — e.g. a user who is just
            // installing tools on a fresh Windows machine has no WSL distro
            // yet, so there is nothing to cache for and this would otherwise
            // be a wasted ~tens-of-MB download on every self-update.
            if ($bridge->distroExists()) {
                $linuxBinaryName = "larakube-linux-$arch";
                $linuxDownloadUrl = $this->getGithubUrl()."/{$repo}/releases/download/$version/$linuxBinaryName";
                $linuxTempPath = $temporaryDirectory->path().'/larakube-linux-update';
                $linuxTarget = $bridge->cachedLinuxBinaryPath() ?? ($targetDir !== '' ? "{$targetDir}/{$linuxBinaryName}" : null);

                if ($linuxTarget !== null) {
                    $this->laraKubeInfo("Downloading $linuxBinaryName for WSL2 lockstep cache...");

                    try {
                        $linuxDownload = Http::withHeaders(['User-Agent' => 'LaraKube-CLI'])->timeout(600)->retry(3, 2000)->sink($linuxTempPath)->get($linuxDownloadUrl);
                        if ($linuxDownload->successful() && is_file($linuxTempPath) && filesize($linuxTempPath) >= 1024) {
                            @mkdir(dirname($linuxTarget), 0755, true);
                            @copy($linuxTempPath, $linuxTarget);
                        }
                    } catch (Exception) {
                        // Best-effort cache update; if it fails we warn below when syncing.
                    }
                }

                $this->laraKubeInfo("🔄 Syncing LaraKube CLI ($version) into Linux ({$bridge->distroName()})...");
                if ($linuxTarget !== null && is_file($linuxTarget) && $bridge->syncLinuxBinaryToWsl($linuxTarget)) {
                    $this->laraKubeInfo("✅ LaraKube Linux binary synced to $version!");
                } else {
                    $this->laraKubeWarn('Could not sync cached Linux binary directly; running update inside WSL...');
                    $bridge->dispatchToWsl(['larakube', 'update', ...($version === 'canary' ? ['--canary'] : []), '--yes']);
                }
            }

            $temporaryDirectory->delete();

            return 0;
        }

        $this->laraKubeInfo('🚚 Installing to /usr/local/bin/larakube (requires sudo)...');

        // Atomic swap via sudo
        $installCmd = 'sudo mv '.escapeshellarg($tempPath).' /usr/local/bin/larakube && sudo chmod +x /usr/local/bin/larakube';
        $exitCode = $this->runInteractive($installCmd);
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

    protected function getGithubApiUrl(): string
    {
        return rtrim((string) (config('app.github.api') ?: 'https://api.github.com'), '/');
    }

    protected function getGithubUrl(): string
    {
        return rtrim((string) (config('app.github.url') ?: 'https://github.com'), '/');
    }

    protected function getGithubRepository(): string
    {
        return (string) (config('app.github.repository') ?: 'luchavez-technologies/larakube-cli');
    }
}
