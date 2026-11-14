<?php

namespace App\Services\CliTools;

use App\Contracts\CliTools\InstallsOnWindows;
use App\Contracts\CliTools\InstallsViaHomebrew;
use App\Support\Os;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class GcloudTool extends AbstractCliTool implements InstallsOnWindows, InstallsViaHomebrew
{
    /** The floor the Google Cloud SDK's own installer states, and below which its vendored urllib3 will not import. */
    private const GCLOUD_MIN_PYTHON = '3.10';

    /** The cask has shipped under two names; whichever succeeds first is the one installed. */
    private const array CASKS = ['gcloud-cli', 'google-cloud-sdk'];

    public function slug(): string
    {
        return 'gcloud';
    }

    public function label(): string
    {
        return 'Google Cloud SDK (gcloud CLI)';
    }

    public function description(): string
    {
        return 'CLI for GCP Compute Engine, GKE clusters, and ADC authentication';
    }

    /** gcloud ships a wrapper script on Windows, not a bare .exe. */
    public function binary(): string
    {
        return self::hostOs() === 'windows' ? 'gcloud.cmd' : 'gcloud';
    }

    public function homebrewInstallCommand(): string
    {
        return implode(' || ', array_map(fn (string $cask): string => "brew install --cask {$cask}", self::CASKS));
    }

    public function homebrewUpgradeCommand(): string
    {
        return implode(' || ', array_map(fn (string $cask): string => "brew upgrade --cask {$cask}", self::CASKS));
    }

    public function homebrewReinstallCommand(): string
    {
        return implode(' || ', array_map(fn (string $cask): string => "brew reinstall --cask {$cask}", self::CASKS));
    }

    /** A failed cask install never falls back to Google's own installer script. */
    public function fallsBackToDownloadWhenHomebrewFails(): bool
    {
        return false;
    }

    /**
     * Google's self-contained Windows zip (no installer, no admin) into
     * ~/google-cloud-sdk — the same install location the Darwin/Linux tarball
     * path already uses, so `candidatePaths()`'s existing GCLOUD entry finds
     * it unchanged. Doesn't bundle Python (the bundled-Python variant's
     * filename embeds a version number with no "latest" alias), so Python is
     * resolved the same way the Darwin/Linux path already does.
     */
    public function installOnWindows(): bool
    {
        $url = 'https://dl.google.com/dl/cloudsdk/channels/rapid/downloads/google-cloud-cli-windows-x86_64.zip';

        if (! $this->httpDownloadZipExtractAll($url, home_path())) {
            $this->setLastError('Could not download or extract the Google Cloud SDK archive.');

            return false;
        }

        if (! $this->isInstalledAfterSettle()) {
            $this->setLastError('Google Cloud SDK archive extracted, but gcloud.cmd was not found at '.home_path('google-cloud-sdk/bin/'.$this->binary()).'.');

            return false;
        }

        // gcloud.cmd shells out to Python — an SDK with none usable is
        // installed but non-functional, so this fails the same way the
        // Darwin/Linux branch above does rather than reporting success.
        if ($this->resolvePython() === null) {
            warning('Google Cloud SDK needs Python '.self::GCLOUD_MIN_PYTHON.' or newer, and none was found.');
            warning('Install Python from https://www.python.org/downloads/windows/, then retry.');
            $this->setLastError('Google Cloud SDK requires Python '.self::GCLOUD_MIN_PYTHON.' or newer. Install Python from https://www.python.org/downloads/windows/ and retry.');

            return false;
        }

        return true;
    }

    /**
     * Browser login after installation. Reads from the terminal, so it goes
     * through runInteractive() (tty()), not runStreaming().
     */
    public function ensureAuth(bool $prompt = true): bool
    {
        $bin = $this->resolveBinary() ?? 'gcloud';
        $quotedBin = Os::quoteExecutable($bin);
        $authed = Process::run($quotedBin.' auth print-access-token'.Os::silenceStderr())->successful();
        if ($authed) {
            return true;
        }

        if (! $prompt) {
            return false;
        }

        if (confirm('Active Google Cloud credentials not found. Open browser to log in now?', default: true)) {
            if (app()->runningUnitTests() || Process::isRecording()) {
                return true;
            }

            return $this->runInteractive("{$quotedBin} auth login --update-adc") === 0;
        }

        return false;
    }

    /** Where installPython()'s no-Homebrew macOS fallback unpacks its standalone Python build. */
    public static function pythonStandaloneDir(): string
    {
        return home_path('.larakube/python-standalone');
    }

    /**
     * A relocatable CPython 3.12 build from astral-sh/python-build-standalone
     * (no installer, no sudo — the minor series is pinned to a known-stable
     * one, but the exact patch release and the one-time download URL are
     * always resolved against that project's current GitHub release, never
     * hardcoded). The `install_only` tarball's own top-level `python/`
     * directory is stripped so the interpreter lands at `$installDir/bin/python3`,
     * matching the prefix layout `resolvePython()` expects.
     */
    public static function pythonStandaloneInstallCommand(string $arch, string $installDir): string
    {
        $pbsArch = $arch === 'arm64' ? 'aarch64' : 'x86_64';

        return 'set -e; '
            .'A=$(curl -fsSL https://api.github.com/repos/astral-sh/python-build-standalone/releases/latest); '
            .'U=$(printf "%s" "$A" | grep -o "https://github.com/astral-sh/python-build-standalone/releases/download/[^\"]*cpython-3\.12\.[0-9]*+[0-9]*-'.$pbsArch.'-apple-darwin-install_only\.tar\.gz" | head -1); '
            .'[ -n "$U" ] || { echo "Could not find a standalone Python 3.12 build for this Mac." >&2; exit 1; }; '
            .'rm -rf '.escapeshellarg($installDir).'; mkdir -p '.escapeshellarg($installDir).'; '
            .'curl -fsSL "$U" | tar -xz -C '.escapeshellarg($installDir).' --strip-components=1';
    }

    protected function unixCandidatePaths(): array
    {
        return [home_path('google-cloud-sdk/bin/'.$this->binary()), '/snap/bin/gcloud', '/usr/bin/gcloud'];
    }

    protected function windowsCandidatePaths(): array
    {
        $localAppData = rtrim((string) getenv('LOCALAPPDATA'), '/\\');
        $programFilesX86 = rtrim((string) getenv('ProgramFiles(x86)'), '/\\');
        $programFiles = rtrim((string) getenv('ProgramFiles'), '/\\');

        return [
            home_path('google-cloud-sdk/bin/'.$this->binary()),
            $localAppData !== '' ? $localAppData.'/Google/Cloud SDK/google-cloud-sdk/bin/'.$this->binary() : null,
            $programFilesX86 !== '' ? $programFilesX86.'/Google/Cloud SDK/google-cloud-sdk/bin/'.$this->binary() : null,
            $programFiles !== '' ? $programFiles.'/Google/Cloud SDK/google-cloud-sdk/bin/'.$this->binary() : null,
        ];
    }

    /** Any SDK tree outside Homebrew and apt/snap updates itself with `gcloud components`. */
    protected function managedPathMarkers(): array
    {
        return ['/google-cloud-sdk/'];
    }

    /**
     * Google's SDK needs Python 3.10+ — its own installer says so and then
     * fails anyway. The bundled install.sh runs under whatever `python3` it
     * finds, and google-cloud-sdk's vendored urllib3 uses PEP 604 type unions
     * (`bytes | str`), so an older interpreter dies with a TypeError raised
     * deep inside urllib3 instead of a version complaint. macOS ships 3.9 with
     * the Command Line Tools, which is exactly the version that breaks. So the
     * interpreter is resolved FIRST and handed over explicitly through
     * CLOUDSDK_PYTHON, which the bundled installer honours.
     */
    protected function installOnUnix(): bool
    {

        $python = $this->resolvePython() ?? $this->installPython();

        if ($python === null) {
            warning('Google Cloud SDK needs Python '.self::GCLOUD_MIN_PYTHON.' or newer, and no usable interpreter could be installed.');
            warning('Could not download a standalone Python build (check your network connection) and no Homebrew/python.org install was found. Install Python from https://www.python.org/downloads/macos/ (or Homebrew: brew install python), then retry.');

            return false;
        }

        $code = $this->runStreaming($this->gcloudInstallCommand(home_path()), env: ['CLOUDSDK_PYTHON' => $python]);

        if ($code !== 0 || ! $this->isInstalled()) {
            return false;
        }

        // gcloud's launcher repeats the same discovery on every invocation, so
        // without this in the environment it finds the broken interpreter again
        // the moment the install finishes.
        warning("Add this to your shell profile so gcloud keeps using a supported Python:\n  export CLOUDSDK_PYTHON={$python}");

        return true;
    }

    /**
     * The Google Cloud SDK ships its own updater — far better than re-running
     * the bundled install.sh against a directory that already exists.
     *
     * `update()` already routes a Homebrew-managed gcloud to brewUpgrade()
     * before this is ever reached, so this only runs for a direct-download
     * install. `components update` re-runs the same launcher as every other
     * gcloud invocation, so it's exposed to the same broken-system-Python
     * discovery installGcloud() exists to route around (see that method's
     * docblock) — that install has no cask-managed Python pinned for it, so
     * CLOUDSDK_PYTHON must be resolved and injected here too.
     */
    protected function updateManagedCopy(): bool
    {
        $bin = $this->resolveBinary() ?? 'gcloud';

        $python = $this->resolvePython() ?? $this->installPython();

        if ($python === null) {
            $message = self::hostOs() === 'windows'
                ? 'Google Cloud SDK needs Python '.self::GCLOUD_MIN_PYTHON.' or newer. Install Python from https://www.python.org/downloads/windows/, then retry.'
                : 'Could not download a standalone Python build (check your network connection) and no Homebrew/python.org install was found. Install Python from https://www.python.org/downloads/macos/ (or Homebrew: brew install python), then retry.';
            warning('Google Cloud SDK needs Python '.self::GCLOUD_MIN_PYTHON.' or newer, and no usable interpreter could be installed.');
            warning($message);
            $this->setLastError($message);

            return false;
        }

        return $this->runStreaming(Os::quoteExecutable($bin).' components update --quiet', env: ['CLOUDSDK_PYTHON' => $python]) === 0;
    }

    /**
     * `gcloud components reinstall` rebuilds a broken SDK in place, with the
     * same CLOUDSDK_PYTHON the updater needs.
     */
    protected function reinstallManagedCopy(): bool
    {
        $bin = $this->resolveBinary() ?? 'gcloud';
        $python = $this->resolvePython() ?? $this->installPython();

        if ($python === null) {
            $this->setLastError('Google Cloud SDK needs Python '.self::GCLOUD_MIN_PYTHON.' or newer to reinstall, and none was found.');

            return false;
        }

        return $this->runStreaming(Os::quoteExecutable($bin).' components reinstall --quiet', env: ['CLOUDSDK_PYTHON' => $python]) === 0;
    }

    /**
     * An interpreter new enough for the Google Cloud SDK, or null.
     *
     * Explicitly versioned names are tried newest-first and before a bare
     * `python3`, which on macOS is the Command Line Tools' 3.9.
     */
    protected function resolvePython(): ?string
    {
        // An operator's own choice wins, and it is the only candidate that
        // comes from outside this file — hence the escaping.
        $fromEnv = (string) (getenv('CLOUDSDK_PYTHON') ?: '');
        if ($fromEnv !== '' && $this->pythonIsSupported($fromEnv)) {
            return $fromEnv;
        }

        if (Os::isWindows()) {
            // Check Google Cloud SDK's own bundled Python when present (e.g.
            // installed by GoogleCloudSDKInstaller.exe).
            $gcloud = $this->resolveBinary();
            if ($gcloud !== null) {
                $bundled = dirname($gcloud, 2).DIRECTORY_SEPARATOR.'platform'.DIRECTORY_SEPARATOR.'bundledpython'.DIRECTORY_SEPARATOR.'python.exe';
                if (is_file($bundled) && $this->pythonIsSupported($bundled)) {
                    return $bundled;
                }
            }

            // Windows has no `command -v` and names its binary `python.exe`.
            foreach (['python', 'python3'] as $name) {
                $output = Process::run('where '.$name)->output();
                foreach (preg_split('/\R/', trim($output)) ?: [] as $candidate) {
                    $candidate = trim($candidate);
                    if ($candidate !== '' && $this->pythonIsSupported($candidate)) {
                        return $candidate;
                    }
                }
            }

            if (! Process::isRecording()) {
                $localAppData = rtrim((string) (getenv('LOCALAPPDATA') ?: ''), '\\/');
                $programFiles = rtrim((string) (getenv('ProgramFiles') ?: ''), '\\/');
                $patterns = array_filter([
                    $localAppData !== '' ? $localAppData.'/Programs/Python/Python3*/python.exe' : null,
                    $programFiles !== '' ? $programFiles.'/Python3*/python.exe' : null,
                    'C:/Python3*/python.exe',
                ]);

                foreach ($patterns as $pattern) {
                    $installs = glob($pattern) ?: [];
                    rsort($installs);

                    foreach ($installs as $path) {
                        if ($this->pythonIsSupported($path)) {
                            return $path;
                        }
                    }
                }
            }

            return null;
        }

        $names = ['python3.14', 'python3.13', 'python3.12', 'python3.11', 'python3.10', 'python3'];

        foreach ($names as $name) {
            $path = trim(Process::run('command -v '.$name)->output());

            if ($path !== '' && $this->pythonIsSupported($path)) {
                return $path;
            }
        }

        // python.org's installers land here rather than on PATH.
        if (self::hostOs() === 'darwin' && ! Process::isRecording()) {
            $frameworks = glob('/Library/Frameworks/Python.framework/Versions/*/bin/python3') ?: [];
            rsort($frameworks);

            foreach ($frameworks as $path) {
                if ($this->pythonIsSupported($path)) {
                    return $path;
                }
            }
        }

        // installPython()'s own no-Homebrew fallback lands here — a single
        // fixed, known path rather than a wildcard glob, so (unlike the
        // Python.framework scan above) it stays safe to check under
        // Process::isRecording() too: home_path() is sandboxed per test, so
        // this never reaches outside that sandbox during a test run.
        if (self::hostOs() === 'darwin') {
            $standalone = self::pythonStandaloneDir().'/bin/python3';
            if (is_file($standalone) && $this->pythonIsSupported($standalone)) {
                return $standalone;
            }
        }

        return null;
    }

    protected function pythonIsSupported(string $bin): bool
    {
        $script = 'import sys; print("%d.%d" % sys.version_info[:2])';
        $version = trim(Process::run(escapeshellarg($bin).' -c '.escapeshellarg($script))->output());

        return $version !== '' && version_compare($version, self::GCLOUD_MIN_PYTHON, '>=');
    }

    /**
     * Install an interpreter, returning its path.
     *
     * Homebrew's `python` formula and every supported distro's `python3`
     * package track a current release, so there is no version to pin here.
     * Without Homebrew on macOS, python.org itself publishes versioned .pkg
     * files behind no "latest" redirect and needs sudo to install (it's a
     * system-wide installer) — neither works for a GUI app with no terminal
     * attached. Instead this downloads a relocatable, no-install "standalone
     * Python" build from astral-sh/python-build-standalone (the same portable
     * CPython distributions `uv`, `rye`, and GitHub Actions' own setup-python
     * use) straight into `~/.larakube/python-standalone`, the same
     * no-elevation pattern as every other tool this enum installs.
     */
    protected function installPython(): ?string
    {
        if (self::hostOs() === 'darwin') {
            if (trim(Process::run('command -v brew')->output()) !== '') {
                if (Process::forever()->run('brew install python')->exitCode() === 0) {
                    $resolved = $this->resolvePython();
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            }

            $arch = self::hostArch();
            $installDir = self::pythonStandaloneDir();
            @mkdir(dirname($installDir), 0755, true);

            if ($this->runStreaming(self::pythonStandaloneInstallCommand($arch, $installDir)) !== 0) {
                return null;
            }

            return $this->resolvePython();
        }

        if (self::hostOs() === 'linux') {
            $installer = match (true) {
                trim(Process::run('command -v apt-get')->output()) !== '' => 'sudo apt-get update -y && sudo apt-get install -y python3',
                trim(Process::run('command -v dnf')->output()) !== '' => 'sudo dnf install -y python3',
                trim(Process::run('command -v apk')->output()) !== '' => 'sudo apk add --no-cache python3',
                default => null,
            };

            if ($installer === null) {
                return null;
            }

            return $this->runInteractive($installer) === 0 ? $this->resolvePython() : null;
        }

        return null;
    }

    /**
     * `bash -s --`, not `bash --`: without -s, bash reads the first argument
     * after -- as the script FILENAME instead of taking the script from the
     * pipe, so it died with "bash: --disable-prompts: No such file or
     * directory" and never ran the installer. -s says "script is on stdin,
     * the rest are positional arguments".
     */
    protected function gcloudInstallCommand(string $installDir): string
    {
        return 'curl -fsSL https://sdk.cloud.google.com | bash -s -- --disable-prompts --install-dir='.escapeshellarg($installDir);
    }
}
