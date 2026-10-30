<?php

namespace App\Enums;

use App\Facades\State;
use App\Support\Os;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

use ZipArchive;

enum CliTool: string
{
    use StreamsProcessOutput;

    /** The floor the Google Cloud SDK's own installer states, and below which its vendored urllib3 will not import. */
    private const GCLOUD_MIN_PYTHON = '3.10';

    /** Used only when Gitea's API cannot be reached, so a flaky lookup does not stop an install. */
    private const string TEA_FALLBACK_VERSION = '0.16.0';

    /**
     * The real reason this tool's last install() attempt failed, if its
     * installer captured one (currently: AWS's MSI installer). Cleared at
     * the start of every install(). Backed by RuntimeContext's transient
     * store via the State facade rather than an enum property — PHP enums
     * cannot declare any state of their own, not even static properties.
     */
    public function lastError(): ?string
    {
        return State::getTransient($this->lastErrorKey());
    }

    public function label(): string
    {
        return match ($this) {
            self::KUBECTL => 'kubectl (Kubernetes CLI)',
            self::K9S => 'k9s (Kubernetes Terminal UI)',
            self::TOFU => 'OpenTofu (Infrastructure Provisioner)',
            self::GCLOUD => 'Google Cloud SDK (gcloud CLI)',
            self::AWS => 'AWS CLI (Amazon Web Services)',
            self::HCLOUD => 'Hetzner Cloud CLI (hcloud)',
            self::GH => 'GitHub CLI (gh)',
            self::TEA => 'Tea CLI (Forgejo / Gitea)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::KUBECTL => 'The Kubernetes client every cluster command shells out to — required, not optional',
            self::K9S => 'Terminal UI for browsing and managing Kubernetes clusters',
            self::TOFU => 'Infrastructure-as-Code provisioner for cloud:create (VPS & Managed)',
            self::GCLOUD => 'CLI for GCP Compute Engine, GKE clusters, and ADC authentication',
            self::AWS => 'CLI for Amazon Web Services (EC2, EKS, IAM, and STS)',
            self::HCLOUD => 'CLI for Hetzner Cloud servers, networks, and firewalls',
            self::GH => 'Native GitHub Actions and GHCR container registry management',
            self::TEA => 'Native Forgejo and Gitea git forge management',
        };
    }

    public function binary(): string
    {
        $name = match ($this) {
            self::KUBECTL => 'kubectl',
            self::K9S => 'k9s',
            self::TOFU => 'tofu',
            self::GCLOUD => 'gcloud',
            self::AWS => 'aws',
            self::HCLOUD => 'hcloud',
            self::GH => 'gh',
            self::TEA => 'tea',
        };

        if (self::hostOs() !== 'windows') {
            return $name;
        }

        // gcloud ships a wrapper script on Windows, not a bare .exe.
        return $this === self::GCLOUD ? "{$name}.cmd" : "{$name}.exe";
    }

    /**
     * Whether this tool is recommended by default during first-time setup.
     */
    public function isDefault(): bool
    {
        return match ($this) {
            self::KUBECTL, self::K9S, self::TOFU => true,
            default => false,
        };
    }

    /**
     * Directory where user-local binaries are installed.
     */
    public static function binDir(): string
    {
        $dir = (string) (getenv('LARAKUBE_BIN_DIR') ?: '');

        return $dir !== '' ? rtrim($dir, '/') : home_path('.larakube/bin');
    }

    /**
     * List of host filesystem paths to check for the binary.
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

        // Confirmed live (CI, 2026-10-26): these bare `/usr/bin/kubectl`-style
        // paths used to be unconditional, so a real kubectl/gcloud/aws
        // genuinely installed at one of them on a Linux CI runner satisfied
        // isInstalled() even while resolving for a Windows-forced test,
        // short-circuiting install() before it ever ran — install() returned
        // true, but nothing was actually downloaded to the test's own binDir.
        if (self::hostOs() === 'windows') {
            return match ($this) {
                self::GCLOUD => array_merge($base, [home_path('google-cloud-sdk/bin/'.$this->binary())]),
                self::AWS => array_merge($base, [
                    // The per-user Windows MSI (AWSCLIV2-User.msi) always
                    // installs here — fixed by the installer, not by us —
                    // never into binDir.
                    rtrim((string) getenv('LOCALAPPDATA'), '/\\').'/Programs/Amazon/AWSCLIV2/aws.exe',
                ]),
                default => $base,
            };
        }

        return match ($this) {
            self::KUBECTL => array_merge($base, ['/usr/bin/kubectl', '/snap/bin/kubectl']),
            self::TOFU => array_merge($base, [
                '/opt/homebrew/bin/terraform',
                '/usr/local/bin/terraform',
                '/home/linuxbrew/.linuxbrew/bin/terraform',
                "{$binDir}/terraform",
            ]),
            self::GCLOUD => array_merge($base, [
                home_path('google-cloud-sdk/bin/'.$this->binary()),
                '/snap/bin/gcloud',
                '/usr/bin/gcloud',
            ]),
            self::AWS => array_merge($base, [
                '/snap/bin/aws',
                '/usr/bin/aws',
            ]),
            default => $base,
        };
    }

    /**
     * Resolve the absolute path to the executable binary on the host, or null if missing.
     */
    public function resolveBinary(): ?string
    {
        // `command -v` is POSIX-only; on Windows it never resolves via the
        // shell, so skip straight to the candidate-path check below.
        if (self::hostOs() !== 'windows') {
            $res = Process::run('command -v '.$this->binary());
            $which = trim($res->output());
            if ($res->successful() && $which !== '' && (@is_executable($which) || Process::isRecording())) {
                return $which;
            }

            // For OpenTofu, also allow existing terraform binary
            if ($this === self::TOFU) {
                $tfRes = Process::run('command -v terraform');
                $tfWhich = trim($tfRes->output());
                if ($tfRes->successful() && $tfWhich !== '' && (@is_executable($tfWhich) || Process::isRecording())) {
                    return $tfWhich;
                }
            }

            // When Process::fake() is active, do not let host candidate paths
            // leak through and override the mock — but only for this `command
            // -v` flow: Windows never calls Process for lookup at all (see
            // above), so a faked `msiexec`/similar elsewhere in the same test
            // must not suppress the real, on-disk candidate-path check below.
            if (Process::isRecording()) {
                return null;
            }
        }

        // Check fallback candidate paths (handles non-login shells and custom install dirs)
        foreach ($this->candidatePaths() as $path) {
            if ($path !== '' && @is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Whether the tool is currently installed and executable on the host.
     */
    public function isInstalled(): bool
    {
        return $this->resolveBinary() !== null;
    }

    /**
     * Install the tool natively using the appropriate platform installer.
     */
    public function install(): bool
    {
        State::setTransient($this->lastErrorKey(), null);

        if ($this->isInstalled()) {
            return true;
        }

        return match ($this) {
            self::KUBECTL => $this->installKubectl(),
            self::K9S => $this->installK9s(),
            self::TOFU => $this->installTofu(),
            self::GCLOUD => $this->installGcloud(),
            self::AWS => $this->installAws(),
            self::HCLOUD => $this->installHcloud(),
            self::GH => $this->installGh(),
            self::TEA => $this->installTea(),
        };
    }

    /**
     * Bring an already-installed tool up to date. Unlike install(), this never
     * short-circuits on "already present" — a brew-managed tool gets
     * `brew upgrade`, and everything else re-runs the same "latest release"
     * download its installer already uses (no version is ever pinned here, so
     * re-running it naturally fetches whatever is current and overwrites the
     * existing binary in place).
     */
    public function update(): bool
    {
        if (! $this->isInstalled()) {
            return $this->install();
        }

        if ($this->isBrewManaged()) {
            return $this->brewUpgrade();
        }

        return match ($this) {
            self::KUBECTL => $this->updateKubectl(),
            self::K9S => $this->updateK9s(),
            self::TOFU => $this->updateTofu(),
            self::GCLOUD => $this->updateGcloud(),
            self::AWS => $this->updateAws(),
            self::HCLOUD => $this->updateHcloud(),
            self::GH => $this->updateGh(),
            self::TEA => $this->updateTea(),
        };
    }

    public static function awsUpdateCommand(string $binDir): string
    {
        return 'curl -fsSL https://awscli.amazonaws.com/v2/install.sh | XDG_BIN_HOME='.escapeshellarg($binDir).' bash -s -- --update';
    }

    /**
     * For tools that support browser/interactive login after installation (e.g. gcloud, aws).
     *
     * These read from the terminal, so they go through runInteractive()
     * (tty()) rather than runStreaming(), whose output callback never attaches
     * stdin.
     */
    public function ensureAuth(bool $prompt = true): bool
    {
        if ($this === self::GCLOUD) {
            $bin = $this->resolveBinary() ?? 'gcloud';
            $authed = Process::run("{$bin} auth print-access-token 2>/dev/null")->successful();
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

                return $this->runInteractive("{$bin} auth login --update-adc") === 0;
            }

            return false;
        }

        if ($this === self::AWS) {
            $bin = $this->resolveBinary() ?? 'aws';
            $authed = Process::run("{$bin} sts get-caller-identity 2>/dev/null")->successful();
            if ($authed) {
                return true;
            }

            if (! $prompt) {
                return false;
            }

            if (confirm('Active AWS credentials not found. Run `aws configure` now?', default: true)) {
                if (app()->runningUnitTests() || Process::isRecording()) {
                    return true;
                }

                return $this->runInteractive("{$bin} configure") === 0;
            }

            return false;
        }

        return true;
    }

    /**
     * Shell that puts the current GitHub CLI into $binDir. The version is read from GitHub's own
     * `/releases/latest` redirect (no API call to rate-limit), so there is no tag here to go stale.
     */
    public static function ghInstallCommand(string $arch, string $binDir, string $os = 'linux'): string
    {
        if ($os === 'darwin' || $os === 'mac') {
            return 'set -e; U=$(curl -fsSLI -o /dev/null -w "%{url_effective}" https://github.com/cli/cli/releases/latest); V="${U##*/v}"; '
                .'[ "$V" != "$U" ] || { echo "Could not find the latest GitHub CLI release." >&2; exit 1; }; '
                .'D="gh_${V}_macOS_'.$arch.'"; T=$(mktemp -d); '
                .'curl -fsSL "https://github.com/cli/cli/releases/download/v${V}/${D}.zip" -o "$T/gh.zip"; '
                .'unzip -q -j "$T/gh.zip" "${D}/bin/gh" -d '.escapeshellarg($binDir).'; rm -rf "$T"';
        }

        return 'set -e; U=$(curl -fsSLI -o /dev/null -w "%{url_effective}" https://github.com/cli/cli/releases/latest); V="${U##*/v}"; '
            .'[ "$V" != "$U" ] || { echo "Could not find the latest GitHub CLI release." >&2; exit 1; }; '
            .'D="gh_${V}_linux_'.$arch.'"; '
            .'curl -fsSL "https://github.com/cli/cli/releases/download/v${V}/${D}.tar.gz" | tar -xz -C '.escapeshellarg($binDir).' --strip-components=2 "${D}/bin/gh"';
    }

    /** Shell that downloads the current tea to $target; the version is the latest release Gitea reports. */
    public static function teaInstallCommand(string $arch, string $target, string $os = 'linux'): string
    {
        $osName = ($os === 'darwin' || $os === 'mac') ? 'darwin' : 'linux';

        return 'set -e; V=$(curl -fsSL https://gitea.com/api/v1/repos/gitea/tea/releases/latest | grep -o \'"tag_name":"[^"]*"\' | head -1 | cut -d\'"\' -f4); V="${V#v}"; '
            .'[ -n "$V" ] || V='.self::TEA_FALLBACK_VERSION.'; '
            .'curl -fsSL -o '.escapeshellarg($target).' "https://dl.gitea.com/tea/${V}/tea-${V}-'.$osName.'-'.$arch.'"';
    }

    public static function kubectlInstallCommand(string $os, string $arch, string $binDir): string
    {
        $target = "{$binDir}/kubectl";

        return 'set -e; V=$(curl -fsSL https://dl.k8s.io/release/stable.txt); T=$(mktemp -d); '
            ."curl -fsSL -o \"\$T/kubectl\" \"https://dl.k8s.io/release/\$V/bin/{$os}/{$arch}/kubectl\"; "
            .'install -m 0755 "$T/kubectl" '.escapeshellarg($target).'; rm -rf "$T"';
    }

    public static function k9sInstallCommand(string $os, string $arch, string $binDir): string
    {
        $targetOs = ($os === 'darwin' || $os === 'mac') ? 'Darwin' : 'Linux';

        return 'set -e; U="https://github.com/derailed/k9s/releases/latest/download/k9s_'.$targetOs.'_'.$arch.'.tar.gz"; '
            .'curl -fsSL "$U" | tar -xz -C '.escapeshellarg($binDir).' k9s';
    }

    public static function tofuInstallCommand(string $os, string $arch, string $binDir): string
    {
        return 'set -e; U=$(curl -fsSLI -o /dev/null -w "%{url_effective}" https://github.com/opentofu/opentofu/releases/latest); V="${U##*/v}"; '
            .'[ "$V" != "$U" ] || { echo "Could not find the latest OpenTofu release." >&2; exit 1; }; '
            .'curl -fsSL "https://github.com/opentofu/opentofu/releases/download/v${V}/tofu_${V}_'.$os.'_'.$arch.'.tar.gz" | tar -xz -C '.escapeshellarg($binDir).' tofu';
    }

    public static function awsInstallCommand(string $binDir): string
    {
        return 'curl -fsSL https://awscli.amazonaws.com/v2/install.sh | XDG_BIN_HOME='.escapeshellarg($binDir).' bash';
    }

    public static function hcloudInstallCommand(string $os, string $arch, string $binDir): string
    {
        $targetOs = ($os === 'darwin' || $os === 'mac') ? 'darwin' : 'linux';
        $url = "https://github.com/hetznercloud/cli/releases/latest/download/hcloud-{$targetOs}-{$arch}.tar.gz";

        return 'curl -fsSL '.escapeshellarg($url).' | tar -xz -C '.escapeshellarg($binDir).' hcloud';
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

    /**
     * Whether the resolved binary lives under a Homebrew prefix — the signal
     * that `brew upgrade` is the right update path instead of re-running the
     * direct-download installer, which would leave Homebrew's own record of
     * the install stale.
     */
    protected function isBrewManaged(): bool
    {
        $bin = $this->resolveBinary();

        return $bin !== null && (str_starts_with($bin, '/opt/homebrew/') || str_starts_with($bin, '/home/linuxbrew/'));
    }

    /**
     * Homebrew's own prefix has occasionally ended up owned by the wrong
     * user — confirmed live (2026-10-24): a `brew install --cask gcloud-cli`
     * failing outright with "/opt/homebrew is not writable", even though
     * Homebrew itself is present and otherwise working.
     *
     * Originally this extracted and re-ran Homebrew's own suggested
     * `sudo chown -R <user> <dirs...>` line verbatim — but a second live
     * failure the same day proved that assumption wrong: a single
     * `brew install` can print an early one-line "<path> is not writable...
     * sudo chown -R <user> <path>" warning, then later — once dependency
     * resolution reaches directories that warning never covered
     * (`share/doc`, `share/zsh/site-functions`, …) — a second, FATAL
     * "The following directories are not writable by your user:" block that
     * lists several more paths with no suggested command at all. The old
     * regex only ever matched the first block, so the retry chowned too
     * little and failed the exact same way. This now collects every
     * absolute path Homebrew mentioned as unwritable, from either format,
     * and chowns all of them in one pass with our own `$(whoami)` rather
     * than depending on Homebrew's own wording for the command itself.
     *
     * Runs with `sudo -n` (never interactive): if passwordless sudo isn't
     * already available — e.g. a local `larakube setup` grants it for the
     * length of the run, same mechanism InstallTool runs use via
     * CliRunner::needsElevation() — this fails immediately rather than
     * hanging on a password prompt nothing can answer when driven from
     * Desktop (no terminal). A human running the CLI directly in a real
     * terminal still sees Homebrew's own suggested fix in the streamed
     * output either way and can run it by hand.
     *
     * @return bool whether at least one unwritable path was found AND the chown exited 0
     */
    protected function attemptHomebrewPermissionFix(string $failedOutput): bool
    {
        $paths = $this->homebrewUnwritablePaths($failedOutput);

        if ($paths === []) {
            return false;
        }

        $code = $this->runStreaming('sudo -n chown -R $(whoami) '.implode(' ', array_map('escapeshellarg', $paths)));

        return $code === 0;
    }

    protected function brewUpgrade(): bool
    {
        // gcloud was installed under one of two possible cask names (whichever
        // succeeded first) — upgrade whichever one is actually present.
        return match ($this) {
            self::GCLOUD => $this->runStreaming('brew upgrade --cask gcloud-cli') === 0
                || $this->runStreaming('brew upgrade --cask google-cloud-sdk') === 0,
            self::KUBECTL => $this->runStreaming('brew upgrade kubernetes-cli') === 0,
            self::K9S => $this->runStreaming('brew upgrade k9s') === 0,
            self::TOFU => $this->runStreaming('brew upgrade opentofu') === 0,
            self::AWS => $this->runStreaming('brew upgrade awscli') === 0,
            self::HCLOUD => $this->runStreaming('brew upgrade hcloud') === 0,
            self::GH => $this->runStreaming('brew upgrade gh') === 0,
            self::TEA => $this->runStreaming('brew upgrade tea') === 0,
        };
    }

    protected function updateKubectl(): bool
    {
        if (self::hostOs() === 'windows') {
            return $this->installKubectlWindows();
        }

        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        return $this->runStreaming(self::kubectlInstallCommand(self::hostOs(), self::hostArch(), $binDir)) === 0 && $this->isInstalled();
    }

    protected function updateK9s(): bool
    {
        if (self::hostOs() === 'windows') {
            return $this->installK9sWindows();
        }

        return $this->runDirectBinaryUpdate(self::k9sInstallCommand(self::hostOs(), self::hostArch(), self::binDir()), 'k9s');
    }

    protected function updateTofu(): bool
    {
        if (self::hostOs() === 'windows') {
            return $this->installTofuWindows();
        }

        return $this->runDirectBinaryUpdate(self::tofuInstallCommand(self::hostOs(), self::hostArch(), self::binDir()), 'tofu');
    }

    protected function updateHcloud(): bool
    {
        return $this->runDirectBinaryUpdate(self::hcloudInstallCommand(self::hostOs(), self::hostArch(), self::binDir()), 'hcloud');
    }

    protected function updateGh(): bool
    {
        return $this->runDirectBinaryUpdate(self::ghInstallCommand(self::hostArch(), self::binDir(), self::hostOs()), 'gh');
    }

    protected function updateTea(): bool
    {
        $binDir = self::binDir();

        return $this->runDirectBinaryUpdate(self::teaInstallCommand(self::hostArch(), "{$binDir}/tea", self::hostOs()), 'tea');
    }

    protected function updateAws(): bool
    {
        if (self::hostOs() === 'windows') {
            return $this->installAwsWindows();
        }

        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        return $this->runStreaming(self::awsUpdateCommand($binDir)) === 0 && $this->isInstalled();
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
    protected function updateGcloud(): bool
    {
        $bin = $this->resolveBinary() ?? 'gcloud';

        $python = $this->resolvePython() ?? $this->installPython();

        if ($python === null) {
            warning('Google Cloud SDK needs Python '.self::GCLOUD_MIN_PYTHON.' or newer, and no usable interpreter could be installed.');
            warning('Could not download a standalone Python build (check your network connection) and no Homebrew/python.org install was found. Install Python from https://www.python.org/downloads/macos/ (or Homebrew: brew install python), then retry.');

            return false;
        }

        return $this->runStreaming(escapeshellarg($bin).' components update --quiet', env: ['CLOUDSDK_PYTHON' => $python]) === 0;
    }

    /**
     * Runs an install command whose job is "download/extract the named file
     * into binDir", then verifies and chmods the result — the shared tail
     * every direct-download tool's installer already repeats inline.
     */
    protected function runDirectBinaryUpdate(string $command, string $filename): bool
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
     * Routed through `Os::family()` (not raw PHP_OS_FAMILY) specifically so
     * `Os::setForcedFamily('Windows')` can exercise the Windows installers
     * in tests without an actual Windows host.
     */
    protected static function hostOs(): string
    {
        return match (Os::family()) {
            'Darwin' => 'darwin',
            'Windows' => 'windows',
            default => 'linux',
        };
    }

    protected static function hostArch(): string
    {
        // Windows's php_uname('m') returns "ARM64" (uppercase), unlike
        // Darwin/Linux's lowercase "arm64"/"aarch64" — this strict,
        // case-sensitive check silently fell through to 'amd64' on a real
        // ARM64 Windows host, same bug class as UpdateCommand's "Unsupported
        // architecture: AMD64" (confirmed live 2026-10-30).
        return in_array(strtolower(Os::machine()), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
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

    /**
     * kubectl is the one tool here that is NOT optional: every cluster command
     * shells out to a bare `kubectl` on PATH (see Kubectl::prefix()).
     *
     * It installs to ~/.larakube/bin (or LARAKUBE_BIN_DIR) without requiring sudo,
     * so non-devs never see password prompts and automated setup succeeds out of the box.
     */
    protected function installKubectl(): bool
    {
        if (self::hostOs() === 'windows') {
            return $this->installKubectlWindows();
        }

        if (PHP_OS_FAMILY === 'Darwin' && trim(Process::run('command -v brew')->output()) !== '') {
            if (Process::forever()->run('brew install kubernetes-cli')->exitCode() === 0 && $this->isInstalled()) {
                return true;
            }
        }

        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux';
        $arch = in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        return $this->runStreaming(self::kubectlInstallCommand($os, $arch, $binDir)) === 0 && $this->isInstalled();
    }

    /**
     * Self-contained twin of InstallsK9s::installK9s().
     */
    protected function installK9s(): bool
    {
        if (self::hostOs() === 'windows') {
            return $this->installK9sWindows();
        }

        if (PHP_OS_FAMILY === 'Darwin' && trim(Process::run('command -v brew')->output()) !== '') {
            if (Process::forever()->run('brew install k9s')->exitCode() === 0 && $this->isInstalled()) {
                return true;
            }
        }

        $arch = in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux';
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $code = Process::forever()->run(self::k9sInstallCommand($os, $arch, $binDir))->exitCode();

        if ($code === 0 && file_exists($binDir.'/k9s')) {
            @chmod($binDir.'/k9s', 0755);

            return true;
        }

        return false;
    }

    /**
     * OpenTofu standalone installer: unpacks the official release archive
     * directly into ~/.larakube/bin without requiring sudo or unzip.
     */
    protected function installTofu(): bool
    {
        if (self::hostOs() === 'windows') {
            return $this->installTofuWindows();
        }

        if (PHP_OS_FAMILY === 'Darwin' && trim(Process::run('command -v brew')->output()) !== '') {
            if (Process::forever()->run('brew install opentofu')->exitCode() === 0 && $this->isInstalled()) {
                return true;
            }
        }

        if (PHP_OS_FAMILY !== 'Darwin' && PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux';
        $arch = in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $code = Process::forever()->run(self::tofuInstallCommand($os, $arch, $binDir))->exitCode();

        if ($code === 0 && file_exists($binDir.'/tofu')) {
            @chmod($binDir.'/tofu', 0755);

            return true;
        }

        return false;
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
    protected function installGcloud(): bool
    {
        if (self::hostOs() === 'windows') {
            return $this->installGcloudWindows();
        }

        if (PHP_OS_FAMILY === 'Darwin' && trim(Process::run('command -v brew')->output()) !== '') {
            [$code, $output] = $this->runStreamingCaptured('brew install --cask gcloud-cli || brew install --cask google-cloud-sdk');

            if ($code !== 0 && $this->attemptHomebrewPermissionFix($output)) {
                $code = $this->runStreaming('brew install --cask gcloud-cli || brew install --cask google-cloud-sdk');
            }

            return $code === 0 && $this->isInstalled();
        }

        if (PHP_OS_FAMILY !== 'Darwin' && PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

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

        $names = ['python3.14', 'python3.13', 'python3.12', 'python3.11', 'python3.10', 'python3'];

        foreach ($names as $name) {
            $path = trim(Process::run('command -v '.$name)->output());

            if ($path !== '' && $this->pythonIsSupported($path)) {
                return $path;
            }
        }

        // python.org's installers land here rather than on PATH.
        if (PHP_OS_FAMILY === 'Darwin' && ! Process::isRecording()) {
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
        if (PHP_OS_FAMILY === 'Darwin') {
            $standalone = self::pythonStandaloneDir().'/bin/python3';
            if (is_file($standalone) && $this->pythonIsSupported($standalone)) {
                return $standalone;
            }
        }

        // python.org's per-user Windows installer lands here rather than on
        // PATH when installed without the "Add to PATH" option checked.
        if (Os::isWindows() && ! Process::isRecording()) {
            $localAppData = (string) (getenv('LOCALAPPDATA') ?: '');
            $installs = $localAppData !== '' ? (glob($localAppData.'/Programs/Python/Python3*/python.exe') ?: []) : [];
            rsort($installs);

            foreach ($installs as $path) {
                if ($this->pythonIsSupported($path)) {
                    return $path;
                }
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
        if (PHP_OS_FAMILY === 'Darwin') {
            if (trim(Process::run('command -v brew')->output()) !== '') {
                if (Process::forever()->run('brew install python')->exitCode() === 0) {
                    $resolved = $this->resolvePython();
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            }

            $arch = in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
            $installDir = self::pythonStandaloneDir();
            @mkdir(dirname($installDir), 0755, true);

            if ($this->runStreaming(self::pythonStandaloneInstallCommand($arch, $installDir)) !== 0) {
                return null;
            }

            return $this->resolvePython();
        }

        if (PHP_OS_FAMILY === 'Linux') {
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

    protected function installGh(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin' && trim(Process::run('command -v brew')->output()) !== '') {
            if (Process::forever()->run('brew install gh')->exitCode() === 0 && $this->isInstalled()) {
                return true;
            }
        }

        if (PHP_OS_FAMILY !== 'Darwin' && PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        $machine = php_uname('m');
        $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux';
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $code = Process::forever()->run(self::ghInstallCommand($arch, $binDir, $os))->exitCode();

        if ($code === 0 && file_exists($binDir.'/gh')) {
            @chmod($binDir.'/gh', 0755);

            return true;
        }

        return false;
    }

    protected function installTea(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin' && trim(Process::run('command -v brew')->output()) !== '') {
            if (Process::forever()->run('brew install tea')->exitCode() === 0 && $this->isInstalled()) {
                return true;
            }
        }

        if (PHP_OS_FAMILY !== 'Darwin' && PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        $machine = php_uname('m');
        $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux';
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $target = "{$binDir}/tea";

        $code = Process::forever()->run(self::teaInstallCommand($arch, $target, $os))->exitCode();
        if ($code === 0 && file_exists($target)) {
            @chmod($target, 0755);

            return true;
        }

        return false;
    }

    protected function installAws(): bool
    {
        if (self::hostOs() === 'windows') {
            return $this->installAwsWindows();
        }

        if (PHP_OS_FAMILY === 'Darwin' && trim(Process::run('command -v brew')->output()) !== '') {
            if (Process::forever()->run('brew install awscli')->exitCode() === 0 && $this->isInstalled()) {
                return true;
            }
        }

        if (PHP_OS_FAMILY !== 'Darwin' && PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        return Process::forever()->run(self::awsInstallCommand($binDir))->exitCode() === 0 && $this->isInstalled();
    }

    protected function installHcloud(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin' && trim(Process::run('command -v brew')->output()) !== '') {
            if (Process::forever()->run('brew install hcloud')->exitCode() === 0 && $this->isInstalled()) {
                return true;
            }
        }

        if (PHP_OS_FAMILY !== 'Darwin' && PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        $machine = php_uname('m');
        $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux';
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $code = Process::forever()->run(self::hcloudInstallCommand($os, $arch, $binDir))->exitCode();
        if ($code === 0 && file_exists($binDir.'/hcloud')) {
            @chmod($binDir.'/hcloud', 0755);

            return true;
        }

        return false;
    }

    /**
     * kubectl's official static binary for Windows — no installer, no admin,
     * downloaded with Laravel's HTTP client rather than shelling to curl
     * (there is no bash on a native Windows host to run the Darwin/Linux
     * one-liner above against).
     */
    protected function installKubectlWindows(): bool
    {
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $version = trim((string) Http::timeout(30)->get('https://dl.k8s.io/release/stable.txt')->body());
        if ($version === '') {
            return false;
        }

        $url = "https://dl.k8s.io/release/{$version}/bin/windows/amd64/kubectl.exe";

        return $this->httpDownloadToFile($url, "{$binDir}/{$this->binary()}") && $this->isInstalled();
    }

    /**
     * k9s's Windows release is a zip, unlike the plain binary tarball Darwin
     * and Linux ship — extract just the exe, same end state as the Unix
     * `tar -xz` path above.
     */
    protected function installK9sWindows(): bool
    {
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $url = 'https://github.com/derailed/k9s/releases/latest/download/k9s_Windows_amd64.zip';

        return $this->httpDownloadZipMember($url, "{$binDir}/{$this->binary()}", 'k9s.exe') && $this->isInstalled();
    }

    /**
     * OpenTofu's Windows asset filename embeds the version, so (unlike k9s)
     * there is no "latest/download" shortcut — the current tag is resolved
     * from GitHub's API first.
     */
    protected function installTofuWindows(): bool
    {
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        $release = Http::timeout(30)->get('https://api.github.com/repos/opentofu/opentofu/releases/latest')->json();
        $version = ltrim((string) ($release['tag_name'] ?? ''), 'v');
        if ($version === '') {
            return false;
        }

        $url = "https://github.com/opentofu/opentofu/releases/download/v{$version}/tofu_{$version}_windows_amd64.zip";

        return $this->httpDownloadZipMember($url, "{$binDir}/{$this->binary()}", 'tofu.exe') && $this->isInstalled();
    }

    /**
     * Google's self-contained Windows zip (no installer, no admin) into
     * ~/google-cloud-sdk — the same install location the Darwin/Linux tarball
     * path already uses, so `candidatePaths()`'s existing GCLOUD entry finds
     * it unchanged. Doesn't bundle Python (the bundled-Python variant's
     * filename embeds a version number with no "latest" alias), so Python is
     * resolved the same way the Darwin/Linux path already does.
     */
    protected function installGcloudWindows(): bool
    {
        $url = 'https://dl.google.com/dl/cloudsdk/channels/rapid/downloads/google-cloud-cli-windows-x86_64.zip';

        if (! $this->httpDownloadZipExtractAll($url, home_path()) || ! $this->isInstalled()) {
            return false;
        }

        // gcloud.cmd shells out to Python — an SDK with none usable is
        // installed but non-functional, so this fails the same way the
        // Darwin/Linux branch above does rather than reporting success.
        if ($this->resolvePython() === null) {
            warning('Google Cloud SDK needs Python '.self::GCLOUD_MIN_PYTHON.' or newer, and none was found.');
            warning('Install Python from https://www.python.org/downloads/windows/, then retry.');

            return false;
        }

        return true;
    }

    /**
     * AWS's own documented per-user MSI (`AWSCLIV2-User.msi`) — confirmed
     * live against AWS's install docs (2026-10-27): installs under
     * `%LOCALAPPDATA%\Programs\Amazon\AWSCLIV2` with no admin rights
     * required, unlike the all-users `AWSCLIV2.msi`. `/qn` runs it silently.
     *
     * `/qn` alone gives msiexec nothing to report — confirmed live
     * (2026-10-30): every failure here collapsed to the same uninformative
     * "Failed to install AWS CLI", with the real exit code thrown away by
     * runStreaming() (which keeps only the bare int) and no MSI log ever
     * requested. `/l*v` requests a verbose log so there is something to
     * read back, and runStreamingCaptured() keeps the exit code so
     * lastError() can report both.
     */
    protected function installAwsWindows(): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'larakube-aws-').'.msi';

        if (! $this->httpDownloadToFile('https://awscli.amazonaws.com/AWSCLIV2-User.msi', $tmp)) {
            State::setTransient($this->lastErrorKey(), 'Could not download the AWS CLI installer.');

            return false;
        }

        $log = tempnam(sys_get_temp_dir(), 'larakube-aws-log-').'.txt';
        [$code] = $this->runStreamingCaptured(
            'msiexec.exe /i '.escapeshellarg($tmp).' /qn /norestart /l*v '.escapeshellarg($log),
        );
        @unlink($tmp);

        $installed = $code === 0 && $this->isInstalled();

        if (! $installed) {
            $logTail = is_file($log) ? self::fileTail($log, 2000) : '';
            State::setTransient($this->lastErrorKey(), $logTail !== ''
                ? "msiexec exited with code {$code}.\n{$logTail}"
                : "msiexec exited with code {$code}.");
        }

        @unlink($log);

        return $installed;
    }

    /**
     * Download a URL straight to a file path, for tools distributed as a
     * bare binary (kubectl.exe) rather than an archive. Uses Laravel's HTTP
     * client instead of shelling to curl, since a native Windows host has no
     * bash to run this file's other installers' curl/tar one-liners against.
     *
     * Streams straight to disk via Guzzle's `sink` option — never buffers
     * the whole response body in PHP memory first. kubectl/OpenTofu/the
     * Google Cloud SDK zip are all 50-150MB; the previous `$response->body()`
     * + `file_put_contents()` approach held the entire file in a PHP string
     * (effectively twice, once in Guzzle's own buffer and once in that
     * string) before ever writing a byte, which blew past PHP's default
     * 128M memory_limit outright — confirmed live (2026-10-29): every
     * Windows tool install past ~75MB failed with "Allowed memory size of
     * 134217728 bytes exhausted."
     *
     * Downloads to a sibling temp file first, then rename()s onto $target —
     * $target is frequently a binary already in use (an existing
     * kubectl.exe being updated in place). Streaming straight onto that live
     * path left it open/partially-written for the whole download, and
     * confirmed live (2026-10-30): a transient lock on it (AV scanning the
     * freshly-written exe, a stray process still holding it) could make
     * this — or the isInstalled() check immediately after — fail even
     * though the file was actually fine moments later. rename() on the same
     * filesystem is atomic and near-instant, so there's effectively no
     * window left for that race. On Windows, rename() refuses to replace an
     * existing destination outright, so $target is removed first; if THAT
     * unlink hits the exact same transient lock, one retry after a brief
     * pause covers it before giving up.
     */
    protected function httpDownloadToFile(string $url, string $target): bool
    {
        $tmp = $target.'.download';
        @unlink($tmp);

        // retry(): GitHub's (and dl.k8s.io's) release CDN occasionally resets
        // the connection mid-download — confirmed live (2026-10-30): "The
        // request was aborted: The connection was closed unexpectedly" on a
        // real Windows machine — almost always transient.
        $response = Http::timeout(120)->retry(3, 2000)->sink($tmp)->get($url);
        if (! $response->successful()) {
            @unlink($tmp);

            return false;
        }

        @chmod($tmp, 0755);

        return self::replaceFile($tmp, $target);
    }

    /**
     * Download a zip and extract one named member (matched by basename,
     * regardless of which folder it's nested under inside the archive) to a
     * target path. Covers k9s/OpenTofu's Windows release zips.
     *
     * Extracts to a sibling temp file and rename()s onto $target, same as
     * httpDownloadToFile() — $target is the same kind of live, possibly
     * already-running binary (k9s.exe/tofu.exe being updated in place), so
     * it's exposed to the identical transient-lock race.
     */
    protected function httpDownloadZipMember(string $url, string $target, string $memberBasename): bool
    {
        $tmpTarget = $target.'.download';
        $tmpZip = tempnam(sys_get_temp_dir(), 'larakube-zip-');
        if ($tmpZip === false) {
            return false;
        }

        // Streams straight to disk (see httpDownloadToFile()'s docblock) so
        // the download itself never buffers in memory. retry(): see
        // httpDownloadToFile()'s docblock on the connection-reset risk.
        $response = Http::timeout(120)->retry(3, 2000)->sink($tmpZip)->get($url);
        if (! $response->successful()) {
            @unlink($tmpZip);

            return false;
        }

        $zip = new ZipArchive;
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);

            return false;
        }

        $extracted = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && basename($name) === $memberBasename) {
                // getFromIndex() decompresses the whole member into a PHP
                // string first — fine for a small config file, but a real
                // binary (tofu.exe/k9s.exe, 100MB+ decompressed) blew past
                // PHP's default 128M memory_limit here too, confirmed live
                // (2026-10-30) even after the download itself was fixed to
                // stream. getStream() instead hands back a read-only
                // resource ZipArchive fills on demand as it's read, so
                // stream_copy_to_stream() moves it to disk in chunks and
                // never holds the whole decompressed file in memory at once.
                $source = $zip->getStream($name);
                $destination = $source !== false ? fopen($tmpTarget, 'wb') : false;

                if ($source !== false && $destination !== false) {
                    $extracted = stream_copy_to_stream($source, $destination) !== false;
                    fclose($destination);
                    @chmod($tmpTarget, 0755);
                }

                if (is_resource($source)) {
                    fclose($source);
                }

                break;
            }
        }

        $zip->close();
        @unlink($tmpZip);

        if (! $extracted) {
            @unlink($tmpTarget);

            return false;
        }

        return self::replaceFile($tmpTarget, $target);
    }

    /**
     * Download a zip and extract its entire contents into a directory —
     * Google Cloud SDK's Windows zip ships a whole `google-cloud-sdk/` tree,
     * not a single binary, mirroring what `tar -xz` does for the Darwin/Linux
     * tarball install above.
     */
    protected function httpDownloadZipExtractAll(string $url, string $destDir): bool
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'larakube-zip-');
        if ($tmpZip === false) {
            return false;
        }

        // Streams straight to disk (see httpDownloadToFile()'s docblock) —
        // the Google Cloud SDK's Windows zip alone is well over 100MB.
        // retry(): see httpDownloadToFile()'s docblock on the connection-reset risk.
        $response = Http::timeout(300)->retry(3, 2000)->sink($tmpZip)->get($url);
        if (! $response->successful()) {
            @unlink($tmpZip);

            return false;
        }

        $zip = new ZipArchive;
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);

            return false;
        }

        @mkdir($destDir, 0755, true);
        $extracted = $zip->extractTo($destDir);
        $zip->close();
        @unlink($tmpZip);

        return $extracted;
    }

    private function lastErrorKey(): string
    {
        return "cli-tool-install-error:{$this->value}";
    }

    /**
     * The last N bytes of a file without reading the whole thing into
     * memory first — an MSI verbose log can run to several MB, and this
     * codebase already hit PHP's 128M memory_limit once this session by
     * buffering a whole file before trimming it.
     */
    private static function fileTail(string $path, int $bytes): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $size = filesize($path) ?: 0;
        fseek($handle, -min($bytes, $size), SEEK_END);
        $tail = stream_get_contents($handle);
        fclose($handle);

        return trim((string) $tail);
    }

    /**
     * Moves a completed download onto its final path, replacing whatever
     * (if anything) is there — retrying once after a short pause if the
     * destination is transiently locked (see httpDownloadToFile()'s
     * docblock). Shared by every installer that writes a single file.
     */
    private static function replaceFile(string $tmp, string $target): bool
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            @unlink($target);
            if (@rename($tmp, $target)) {
                return true;
            }

            Sleep::usleep(200_000);
        }

        @unlink($tmp);

        return false;
    }

    /**
     * Every absolute path Homebrew flagged as not writable, across however
     * many of its two known message shapes a single run printed:
     * "<path> is not writable...", its own suggested "sudo chown -R <user>
     * <path...>" line, and a bare "The following directories are not
     * writable by your user:\n<path>\n<path>\n..." block (confirmed live
     * 2026-10-24 to appear with NO suggested command of its own).
     *
     * @return list<string>
     */
    private function homebrewUnwritablePaths(string $output): array
    {
        $paths = [];

        // Not line-anchored: Homebrew's own phrasing is "Error: <path> is not
        // writable.", so the path never starts the line.
        if (preg_match_all('#(/\S+) is not writable\.#', $output, $matches) > 0) {
            array_push($paths, ...$matches[1]);
        }

        if (preg_match_all('/^\s*sudo chown -R \S+((?: \S+)+)\s*$/m', $output, $matches) > 0) {
            foreach ($matches[1] as $group) {
                array_push($paths, ...array_filter(explode(' ', trim($group))));
            }
        }

        if (preg_match_all('/not writable by your user:\n((?:\/\S+\n?)+)/', $output, $matches) > 0) {
            foreach ($matches[1] as $block) {
                array_push($paths, ...array_filter(explode("\n", trim($block))));
            }
        }

        return array_values(array_unique($paths));
    }

    case KUBECTL = 'kubectl';
    case K9S = 'k9s';
    case TOFU = 'tofu';
    case GCLOUD = 'gcloud';
    case AWS = 'aws';
    case HCLOUD = 'hcloud';
    case GH = 'gh';
    case TEA = 'tea';
}
