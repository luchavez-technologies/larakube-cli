<?php

namespace App\Services\CliTools;

use App\Contracts\CliTools\InstallsOnWindows;
use App\Contracts\CliTools\InstallsViaHomebrew;
use App\Contracts\CliTools\RunsInWslOnWindows;
use App\Enums\CliTool;
use App\Enums\InstallChannel;
use App\Facades\State;
use App\Services\WslBridge;
use App\Support\Os;
use App\Traits\CliTools\DownloadsReleaseArtifacts;
use App\Traits\CliTools\RepairsHomebrewPermissions;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * One CLI tool the LaraKube CLI resolves, installs, updates and reinstalls.
 *
 * The install/update/reinstall flow lives here once. A tool class only states
 * its own facts: where it can live, how to download it on Darwin/Linux
 * (installOnUnix()), and, through contracts, whether Homebrew packages it
 * (InstallsViaHomebrew) and whether it has a native Windows build
 * (InstallsOnWindows).
 *
 * Update and reinstall always follow the channel the installed binary came
 * from (InstallChannel): Homebrew's copy is upgraded by Homebrew, the CLI's
 * own copy is re-downloaded, and a copy owned by apt/snap or anything else is
 * left alone with an explanation, never shadowed by a second copy.
 */
abstract class AbstractCliTool
{
    use DownloadsReleaseArtifacts, RepairsHomebrewPermissions, StreamsProcessOutput;

    abstract public function slug(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    public function tool(): CliTool
    {
        return CliTool::from($this->slug());
    }

    /** Recommended during first-time setup. */
    public function isDefault(): bool
    {
        return false;
    }

    /** @return list<string> */
    public function versionArguments(): array
    {
        return ['--version'];
    }

    public function binary(): string
    {
        return self::hostOs() === 'windows' ? "{$this->slug()}.exe" : $this->slug();
    }

    /** Directory where user-local binaries are installed. */
    public static function binDir(): string
    {
        $dir = (string) (getenv('LARAKUBE_BIN_DIR') ?: '');

        return $dir !== '' ? rtrim($dir, '/') : home_path('.larakube/bin');
    }

    /**
     * Host filesystem paths to check for the binary.
     *
     * @return list<string>
     */
    public function candidatePaths(): array
    {
        $bin = $this->binary();
        $binDir = self::binDir();

        $base = [
            "/opt/homebrew/bin/{$bin}",
            "/usr/local/bin/{$bin}",
            "/home/linuxbrew/.linuxbrew/bin/{$bin}",
            "{$binDir}/{$bin}",
            home_path(".local/bin/{$bin}"),
        ];

        // Bare `/usr/bin/kubectl`-style paths stay Darwin/Linux-only: a real
        // binary at one of them on a Linux CI runner used to satisfy
        // isInstalled() while resolving for a Windows-forced test.
        $extra = self::hostOs() === 'windows' ? $this->windowsCandidatePaths() : $this->unixCandidatePaths();

        return array_values(array_filter([...$base, ...$extra], fn (?string $path): bool => $path !== null && $path !== ''));
    }

    /** Resolve the absolute path to the executable binary on the host, or null if missing. */
    public function resolveBinary(): ?string
    {
        // `command -v` is POSIX-only; on Windows it never resolves via the
        // shell, so skip straight to the candidate-path check below.
        if (self::hostOs() !== 'windows') {
            foreach ([$this->binary(), ...$this->alternateBinaries()] as $name) {
                $result = Process::run('command -v '.$name);
                $which = trim($result->output());

                if ($result->successful() && $which !== '' && (@is_executable($which) || Process::isRecording())) {
                    return $which;
                }
            }

            // When Process::fake() is active, do not let host candidate paths
            // leak through and override the mock — only for this `command -v`
            // flow: Windows never calls Process for lookup at all, so a faked
            // `msiexec`/similar elsewhere in the same test must not suppress
            // the real, on-disk candidate-path check below.
            if (Process::isRecording()) {
                return null;
            }
        }

        // On Windows, .cmd wrappers (gcloud.cmd) are not PE executables and
        // PHP's is_executable() is unreliable for them, so check is_file().
        foreach ($this->candidatePaths() as $path) {
            if (self::hostOs() === 'windows' ? @is_file($path) : @is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    public function isInstalled(): bool
    {
        return $this->resolveBinary() !== null;
    }

    /**
     * Whether this tool can run here at all: installed on this host, or, for a
     * RunsInWslOnWindows tool on Windows, bundled in the larakube-ubuntu distro.
     */
    public function isAvailable(): bool
    {
        return $this->isInstalled() || $this->wslCommand() !== null;
    }

    /**
     * The command prefix callers append arguments to: the host binary when
     * there is one, else (RunsInWslOnWindows on Windows) the distro's copy
     * through `wsl.exe`, else the bare name for PATH to find.
     *
     * @param  list<string>  $forwardEnv  variables the caller sets on the process that must reach the distro's copy too
     */
    public function command(array $forwardEnv = []): string
    {
        return $this->resolveBinary() ?? $this->wslCommand($forwardEnv) ?? $this->slug();
    }

    /** Who owns the installed binary, or null when it is not installed. */
    public function channel(): ?InstallChannel
    {
        $path = $this->resolveBinary();

        return $path === null ? null : InstallChannel::detect($path, self::binDir(), $this->managedPathMarkers());
    }

    /** Whether this host can install the tool natively (Windows needs a verified native installer). */
    public function isInstallableHere(): bool
    {
        return self::hostOs() !== 'windows' || $this instanceof InstallsOnWindows;
    }

    /** Install the tool if it is missing. */
    public function install(): bool
    {
        $this->setLastError(null);

        if ($this->isInstalled()) {
            return true;
        }

        return $this->freshInstall();
    }

    /**
     * Bring an installed tool up to date through the channel that owns it.
     * A missing tool is installed instead.
     */
    public function update(): bool
    {
        $this->setLastError(null);
        $path = $this->resolveBinary();

        if ($path === null) {
            return $this->freshInstall();
        }

        return match (InstallChannel::detect($path, self::binDir(), $this->managedPathMarkers())) {
            InstallChannel::Homebrew => $this->runHomebrew('upgrade', $path),
            InstallChannel::LaraKube => $this->updateManagedCopy(),
            InstallChannel::System, InstallChannel::Other => $this->refuseUnmanaged('update', $path),
        };
    }

    /**
     * Replace an installed tool with a fresh copy, for a corrupted or broken
     * binary that update() cannot fix (Homebrew sees nothing to upgrade, a
     * self-updater cannot start). A missing tool is installed instead.
     */
    public function reinstall(): bool
    {
        $this->setLastError(null);
        $path = $this->resolveBinary();

        if ($path === null) {
            return $this->freshInstall();
        }

        return match (InstallChannel::detect($path, self::binDir(), $this->managedPathMarkers())) {
            InstallChannel::Homebrew => $this->runHomebrew('reinstall', $path),
            InstallChannel::LaraKube => $this->reinstallManagedCopy(),
            InstallChannel::System, InstallChannel::Other => $this->refuseUnmanaged('reinstall', $path),
        };
    }

    /**
     * Everything `setup:status` reports for this tool.
     *
     * @return array{slug: string, label: string, binary: string, default: bool, installed: bool, path: ?string, channel: ?string, managed: bool, installable: bool, version: ?string}
     */
    public function status(): array
    {
        $path = $this->resolveBinary();
        $channel = $path === null ? null : InstallChannel::detect($path, self::binDir(), $this->managedPathMarkers());

        return [
            'slug' => $this->slug(),
            'label' => $this->label(),
            'binary' => $this->binary(),
            'default' => $this->isDefault(),
            'installed' => $path !== null,
            'path' => $path,
            'channel' => $channel?->value,
            'managed' => $channel?->isManagedByCli() ?? false,
            'installable' => $this->isInstallableHere(),
            'version' => $path === null ? null : $this->version($path),
        ];
    }

    /** First line of the tool's own version output, or null if it cannot say. */
    public function version(string $path): ?string
    {
        try {
            $result = Process::timeout(30)->run([$path, ...$this->versionArguments()]);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $first = trim((string) strtok(trim($result->output()) ?: trim($result->errorOutput()), "\n"));
        $first = (string) preg_replace(['/\e\[[0-9;]*m/', '/\s+/'], ['', ' '], $first);

        return $first !== '' ? $first : null;
    }

    /** For tools that support browser/interactive login after installation. */
    public function ensureAuth(bool $prompt = true): bool
    {
        return true;
    }

    /** The real reason the last install/update/reinstall failed, when one was captured. */
    public function lastError(): ?string
    {
        return State::getTransient($this->lastErrorKey());
    }

    /**
     * Routed through `Os::family()` (not raw PHP_OS_FAMILY) so
     * `Os::setForcedFamily('Windows')` exercises the Windows installers in
     * tests without a Windows host.
     */
    public static function hostOs(): string
    {
        return match (Os::family()) {
            'Darwin' => 'darwin',
            'Windows' => 'windows',
            default => 'linux',
        };
    }

    /** Windows reports "ARM64" (uppercase), Darwin/Linux "arm64"/"aarch64". */
    public static function hostArch(): string
    {
        return in_array(strtolower(Os::machine()), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
    }

    /**
     * @param  list<string>  $forwardEnv
     */
    protected function wslCommand(array $forwardEnv = []): ?string
    {
        if (self::hostOs() !== 'windows' || ! $this instanceof RunsInWslOnWindows) {
            return null;
        }

        $bridge = app(WslBridge::class);

        if (! $bridge->distroExists()) {
            return null;
        }

        if ($forwardEnv !== []) {
            // wsl.exe only hands a variable to the distro when WSLENV names it.
            $names = array_filter(explode(':', (string) (getenv('WSLENV') ?: '')));
            putenv('WSLENV='.implode(':', array_values(array_unique([...$names, ...$forwardEnv]))));
        }

        return 'wsl.exe -d '.$bridge->distroName().' --user '.WslBridge::DEFAULT_USER.' --exec '.$this->slug();
    }

    /** Download the current release into binDir() on Darwin/Linux. */
    abstract protected function installOnUnix(): bool;

    /** @return list<?string> */
    protected function unixCandidatePaths(): array
    {
        return [];
    }

    /** @return list<?string> */
    protected function windowsCandidatePaths(): array
    {
        return [];
    }

    /** Other binary names that satisfy this tool on Darwin/Linux (OpenTofu accepts terraform). */
    protected function alternateBinaries(): array
    {
        return [];
    }

    /**
     * Path fragments (forward slashes) that mark an install the LaraKube CLI
     * made outside binDir() — a vendor installer's own fixed location.
     *
     * @return list<string>
     */
    protected function managedPathMarkers(): array
    {
        return [];
    }

    /** Install from scratch, ignoring whatever is (or is not) installed now. */
    protected function freshInstall(): bool
    {
        if (self::hostOs() === 'windows') {
            if ($this instanceof InstallsOnWindows) {
                return $this->installOnWindows();
            }

            $this->setLastError("{$this->label()} has no native Windows installer; it runs inside the larakube-ubuntu WSL distro.");

            return false;
        }

        if ($this instanceof InstallsViaHomebrew && $this->homebrewAvailable()) {
            $installed = $this->installViaHomebrew();

            if ($installed !== null) {
                return $installed;
            }
        }

        if (! in_array(self::hostOs(), ['darwin', 'linux'], true)) {
            return false;
        }

        return $this->installOnUnix();
    }

    /** Update the LaraKube CLI's own copy: re-download over it by default. */
    protected function updateManagedCopy(): bool
    {
        return $this->downloadOverManagedCopy();
    }

    /** Reinstall the LaraKube CLI's own copy: re-download over it by default. */
    protected function reinstallManagedCopy(): bool
    {
        return $this->downloadOverManagedCopy();
    }

    protected function downloadOverManagedCopy(): bool
    {
        if (self::hostOs() === 'windows') {
            return $this instanceof InstallsOnWindows && $this->installOnWindows();
        }

        return $this->installOnUnix();
    }

    /**
     * `brew install`, self-healing the Homebrew prefix permission error once.
     * Returns null when the caller may fall back to the CLI's own download:
     * only when Homebrew failed AND left nothing installed, so no second copy.
     */
    protected function installViaHomebrew(): ?bool
    {
        assert($this instanceof InstallsViaHomebrew);
        $command = $this->homebrewInstallCommand();

        [$code, $output] = $this->runStreamingCaptured($command);

        if ($code !== 0 && $this->attemptHomebrewPermissionFix($output)) {
            $code = $this->runStreaming($command);
        }

        if ($code === 0 && $this->isInstalled()) {
            return true;
        }

        if ($this->fallsBackToDownloadWhenHomebrewFails() && ! $this->isInstalled()) {
            return null;
        }

        return false;
    }

    protected function runHomebrew(string $operation, string $path): bool
    {
        if (! $this instanceof InstallsViaHomebrew) {
            $this->setLastError("{$this->label()} at {$path} belongs to Homebrew, but Homebrew does not package it under a name the LaraKube CLI knows. Run `brew {$operation}` for it yourself.");

            return false;
        }

        $command = $operation === 'reinstall' ? $this->homebrewReinstallCommand() : $this->homebrewUpgradeCommand();

        if ($this->runStreaming($command) === 0) {
            return true;
        }

        $this->setLastError("`{$command}` failed.");

        return false;
    }

    protected function refuseUnmanaged(string $operation, string $path): bool
    {
        $channel = InstallChannel::detect($path, self::binDir(), $this->managedPathMarkers());
        $real = @realpath($path);
        $where = $real !== false && $real !== $path ? "{$path} (→ {$real})" : $path;

        $this->setLastError("{$this->label()} at {$where} is managed by {$channel->label()}, not the LaraKube CLI, so it was left alone. "
            ."{$this->operationVerb($operation)} it there; installing a second copy would leave whichever comes first on PATH in charge.");

        return false;
    }

    protected function homebrewAvailable(): bool
    {
        return self::hostOs() === 'darwin' && trim(Process::run('command -v brew')->output()) !== '';
    }

    /**
     * Download/extract a single file into binDir(), then verify and chmod it.
     */
    protected function downloadIntoBinDir(string $command, string $filename): bool
    {
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $code = Process::forever()->run($command)->exitCode();
        $target = "{$binDir}/{$filename}";

        if ($code === 0 && file_exists($target)) {
            @chmod($target, 0755);

            return true;
        }

        return false;
    }

    /**
     * Re-checks isInstalled() a few times, briefly apart, before giving up —
     * for right after this tool's own installer wrote the binary. On Windows a
     * freshly written .exe can fail the check for a moment while antivirus
     * scans it, even though the file is fine microseconds later.
     */
    protected function isInstalledAfterSettle(): bool
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($this->isInstalled()) {
                return true;
            }

            Sleep::usleep(200_000);
        }

        return false;
    }

    protected function setLastError(?string $message): void
    {
        State::setTransient($this->lastErrorKey(), $message);
    }

    private function operationVerb(string $operation): string
    {
        return $operation === 'reinstall' ? 'Reinstall' : 'Update';
    }

    private function lastErrorKey(): string
    {
        return "cli-tool-install-error:{$this->slug()}";
    }
}
