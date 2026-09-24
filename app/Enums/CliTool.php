<?php

namespace App\Enums;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

enum CliTool: string
{
    public function label(): string
    {
        return match ($this) {
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
            self::K9S, self::TOFU => true,
            default => false,
        };
    }

    /**
     * List of host filesystem paths to check for the binary.
     *
     * @return list<string>
     */
    public function candidatePaths(): array
    {
        $bin = $this->binary();
        $binDir = home_path('.larakube/bin');

        $base = [
            "/opt/homebrew/bin/{$bin}",
            "/usr/local/bin/{$bin}",
            "/home/linuxbrew/.linuxbrew/bin/{$bin}",
            "{$binDir}/{$bin}",
        ];

        return match ($this) {
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
                home_path('.local/bin/aws'),
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
                passthru("{$bin} auth login --update-adc", $code);

                return $code === 0;
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
                passthru("{$bin} configure", $code);

                return $code === 0;
            }

            return false;
        }

        return true;
    }

    /**
     * The command that installs the Google Cloud SDK from Google's own script.
     *
     * Split out from installGcloud() so it can be asserted without running an
     * installer: the shape of this one string is the whole bug surface (see
     * the -s note below), and passthru() is not fakeable.
     */
    public static function gcloudInstallCommand(string $installDir): string
    {
        // `bash -s --`, not `bash --`: without -s, bash reads the first
        // argument after -- as the script FILENAME instead of taking the
        // script from the pipe, so it died with
        // "bash: --disable-prompts: No such file or directory" and never ran
        // the installer. -s says "script is on stdin, the rest are positional
        // arguments".
        return 'curl -fsSL https://sdk.cloud.google.com | bash -s -- --disable-prompts --install-dir='.escapeshellarg($installDir);
    }

    protected function installK9s(): bool
    {
        $version = 'v0.32.5';

        if (PHP_OS_FAMILY === 'Darwin') {
            if (trim(Process::run('command -v brew')->output()) === '') {
                return false;
            }

            return Process::forever()->run('brew install k9s')->exitCode() === 0;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $machine = php_uname('m');
            $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
            $binDir = home_path('.larakube/bin');
            @mkdir($binDir, 0755, true);
            $url = "https://github.com/derailed/k9s/releases/download/{$version}/k9s_linux_{$arch}.tar.gz";

            $code = Process::forever()->run('curl -fsSL '.escapeshellarg($url).' | tar -xz -C '.escapeshellarg($binDir).' k9s')->exitCode();
            if ($code === 0 && file_exists($binDir.'/k9s')) {
                @chmod($binDir.'/k9s', 0755);

                return true;
            }
        }

        return false;
    }

    protected function installTofu(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            if (trim(Process::run('command -v brew')->output()) === '') {
                return false;
            }

            return Process::forever()->run('brew install opentofu')->exitCode() === 0;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $script = 'curl -fsSL https://get.opentofu.org/install-opentofu.sh -o /tmp/tofu-install.sh && chmod +x /tmp/tofu-install.sh && sudo /tmp/tofu-install.sh --install-method standalone; rm -f /tmp/tofu-install.sh';
            passthru($script, $code);

            return $code === 0;
        }

        return false;
    }

    protected function installGcloud(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            if (trim(Process::run('command -v brew')->output()) === '') {
                // Fallback to official Google user-space script if brew is absent
                $installDir = home_path();
                passthru(self::gcloudInstallCommand($installDir), $code);

                return $code === 0 && $this->isInstalled();
            }

            passthru('brew install --cask gcloud-cli || brew install --cask google-cloud-sdk', $code);

            return $code === 0 && $this->isInstalled();
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $installDir = home_path();
            passthru(self::gcloudInstallCommand($installDir), $code);

            return $code === 0 && $this->isInstalled();
        }

        return false;
    }

    protected function installGh(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            if (trim(Process::run('command -v brew')->output()) === '') {
                return false;
            }

            return Process::forever()->run('brew install gh')->exitCode() === 0;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $machine = php_uname('m');
            $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
            $binDir = home_path('.larakube/bin');
            @mkdir($binDir, 0755, true);

            $version = '2.67.0';
            $tarName = "gh_{$version}_linux_{$arch}";
            $url = "https://github.com/cli/cli/releases/download/v{$version}/{$tarName}.tar.gz";

            $cmd = 'curl -fsSL '.escapeshellarg($url).' | tar -xz -C '.escapeshellarg($binDir).' --strip-components=2 '.escapeshellarg("{$tarName}/bin/gh");
            $code = Process::forever()->run($cmd)->exitCode();

            if ($code === 0 && file_exists($binDir.'/gh')) {
                @chmod($binDir.'/gh', 0755);

                return true;
            }
        }

        return false;
    }

    protected function installTea(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            if (trim(Process::run('command -v brew')->output()) === '') {
                return false;
            }

            return Process::forever()->run('brew install tea')->exitCode() === 0;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $machine = php_uname('m');
            $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
            $binDir = home_path('.larakube/bin');
            @mkdir($binDir, 0755, true);

            $version = '0.16.0';
            $url = "https://dl.gitea.com/tea/{$version}/tea-{$version}-linux-{$arch}";
            $target = "{$binDir}/tea";

            $code = Process::forever()->run('curl -fsSL -o '.escapeshellarg($target).' '.escapeshellarg($url))->exitCode();
            if ($code === 0 && file_exists($target)) {
                @chmod($target, 0755);

                return true;
            }
        }

        return false;
    }

    protected function installAws(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            if (trim(Process::run('command -v brew')->output()) === '') {
                return false;
            }

            return Process::forever()->run('brew install awscli')->exitCode() === 0;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $machine = php_uname('m');
            $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'aarch64' : 'x86_64';
            $url = "https://awscli.amazonaws.com/awscli-exe-linux-{$arch}.zip";

            $cmd = 'TMP_DIR=$(mktemp -d) && curl -fsSL '.escapeshellarg($url).' -o "$TMP_DIR/awscliv2.zip" && unzip -q "$TMP_DIR/awscliv2.zip" -d "$TMP_DIR" && (sudo "$TMP_DIR/aws/install" --update 2>/dev/null || "$TMP_DIR/aws/install" -i ~/.local/aws-cli -b ~/.local/bin --update) && rm -rf "$TMP_DIR"';

            return Process::forever()->run($cmd)->exitCode() === 0;
        }

        return false;
    }

    protected function installHcloud(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            if (trim(Process::run('command -v brew')->output()) === '') {
                return false;
            }

            return Process::forever()->run('brew install hcloud')->exitCode() === 0;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $machine = php_uname('m');
            $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
            $binDir = home_path('.larakube/bin');
            @mkdir($binDir, 0755, true);
            $url = "https://github.com/hetznercloud/cli/releases/latest/download/hcloud-linux-{$arch}.tar.gz";

            $code = Process::forever()->run('curl -fsSL '.escapeshellarg($url).' | tar -xz -C '.escapeshellarg($binDir).' hcloud')->exitCode();
            if ($code === 0 && file_exists($binDir.'/hcloud')) {
                @chmod($binDir.'/hcloud', 0755);

                return true;
            }
        }

        return false;
    }

    case K9S = 'k9s';
    case TOFU = 'tofu';
    case GCLOUD = 'gcloud';
    case AWS = 'aws';
    case HCLOUD = 'hcloud';
    case GH = 'gh';
    case TEA = 'tea';
}
