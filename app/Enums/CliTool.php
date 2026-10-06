<?php

namespace App\Enums;

use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

enum CliTool: string
{
    use StreamsProcessOutput;

    /** The floor the Google Cloud SDK's own installer states, and below which its vendored urllib3 will not import. */
    private const GCLOUD_MIN_PYTHON = '3.10';

    /** Used only when Gitea's API cannot be reached, so a flaky lookup does not stop an install. */
    private const string TEA_FALLBACK_VERSION = '0.16.0';

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
        return match ($this) {
            self::KUBECTL => 'kubectl',
            self::K9S => 'k9s',
            self::TOFU => 'tofu',
            self::GCLOUD => 'gcloud',
            self::AWS => 'aws',
            self::HCLOUD => 'hcloud',
            self::GH => 'gh',
            self::TEA => 'tea',
        };
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

        return match ($this) {
            self::KUBECTL => array_merge($base, ['/usr/bin/kubectl', '/snap/bin/kubectl']),
            self::TOFU => array_merge($base, [
                '/opt/homebrew/bin/terraform',
                '/usr/local/bin/terraform',
                '/home/linuxbrew/.linuxbrew/bin/terraform',
                "{$binDir}/terraform",
            ]),
            self::GCLOUD => array_merge($base, [
                home_path('google-cloud-sdk/bin/gcloud'),
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

        // When Process::fake() is active, do not let host candidate paths leak through and override the mock
        if (Process::isRecording()) {
            return null;
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
        if (PHP_OS_FAMILY === 'Darwin' && trim(Process::run('command -v brew')->output()) !== '') {
            $code = $this->runStreaming('brew install --cask gcloud-cli || brew install --cask google-cloud-sdk');

            return $code === 0 && $this->isInstalled();
        }

        if (PHP_OS_FAMILY !== 'Darwin' && PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        $python = $this->resolvePython() ?? $this->installPython();

        if ($python === null) {
            warning('Google Cloud SDK needs Python '.self::GCLOUD_MIN_PYTHON.' or newer, and no usable interpreter could be installed.');
            warning('Install Python from https://www.python.org/downloads/macos/ (or Homebrew: brew install python), then retry.');

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
     * Without Homebrew on macOS there is no unattended, unpinned way to get
     * one — python.org publishes versioned .pkg files behind no "latest"
     * redirect — so this returns null and lets the caller say so plainly
     * rather than guessing a download URL that will rot.
     */
    protected function installPython(): ?string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            if (trim(Process::run('command -v brew')->output()) === '') {
                return null;
            }

            return Process::forever()->run('brew install python')->exitCode() === 0
                ? $this->resolvePython()
                : null;
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

    case KUBECTL = 'kubectl';
    case K9S = 'k9s';
    case TOFU = 'tofu';
    case GCLOUD = 'gcloud';
    case AWS = 'aws';
    case HCLOUD = 'hcloud';
    case GH = 'gh';
    case TEA = 'tea';
}
