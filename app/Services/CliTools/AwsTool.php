<?php

namespace App\Services\CliTools;

use App\Contracts\CliTools\InstallsOnWindows;
use App\Contracts\CliTools\InstallsViaHomebrew;
use App\Support\Os;
use App\Traits\CliTools\ManagesHomebrewPackage;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

use function Laravel\Prompts\confirm;

class AwsTool extends AbstractCliTool implements InstallsOnWindows, InstallsViaHomebrew
{
    use ManagesHomebrewPackage;

    /** ERROR_INSTALL_PACKAGE_OPEN_FAILED: msiexec could not even open the package (seen live right after a verified-present download, consistent with antivirus briefly locking it). */
    private const MSI_PACKAGE_OPEN_FAILED = 1619;

    public function slug(): string
    {
        return 'aws';
    }

    public function label(): string
    {
        return 'AWS CLI (Amazon Web Services)';
    }

    public function description(): string
    {
        return 'CLI for Amazon Web Services (EC2, EKS, IAM, and STS)';
    }

    public function installOnWindows(): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'larakube-aws-').'.msi';

        if (! $this->httpDownloadToFile('https://awscli.amazonaws.com/AWSCLIV2-User.msi', $tmp)) {
            $this->setLastError('Could not download the AWS CLI installer.');

            return false;
        }

        $code = null;
        $logTail = '';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $log = tempnam(sys_get_temp_dir(), 'larakube-aws-log-').'.txt';
            [$code] = $this->runStreamingCaptured(
                'msiexec.exe /i '.escapeshellarg($tmp).' /qn /norestart /l*v '.escapeshellarg($log),
            );
            $logTail = is_file($log) ? self::fileTail($log, 2000) : '';
            @unlink($log);

            if ($code === 0 || $code !== self::MSI_PACKAGE_OPEN_FAILED || ! is_file($tmp)) {
                break;
            }

            Sleep::usleep(300_000);
        }

        @unlink($tmp);

        $installed = $code === 0 && $this->isInstalledAfterSettle();

        if (! $installed) {
            $this->setLastError($logTail !== ''
                ? "msiexec exited with code {$code}.\n{$logTail}"
                : "msiexec exited with code {$code}.");
        }

        return $installed;
    }

    /** `aws configure` after installation, through runInteractive() (tty()). */
    public function ensureAuth(bool $prompt = true): bool
    {
        $bin = $this->resolveBinary() ?? 'aws';
        $quotedBin = Os::quoteExecutable($bin);
        $authed = Process::run($quotedBin.' sts get-caller-identity'.Os::silenceStderr())->successful();
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

            return $this->runInteractive("{$quotedBin} configure") === 0;
        }

        return false;
    }

    public static function awsInstallCommand(string $binDir): string
    {
        return 'curl -fsSL https://awscli.amazonaws.com/v2/install.sh | XDG_BIN_HOME='.escapeshellarg($binDir).' bash';
    }

    public static function awsUpdateCommand(string $binDir): string
    {
        return 'curl -fsSL https://awscli.amazonaws.com/v2/install.sh | XDG_BIN_HOME='.escapeshellarg($binDir).' bash -s -- --update';
    }

    protected function homebrewPackage(): string
    {
        return 'awscli';
    }

    protected function unixCandidatePaths(): array
    {
        return ['/snap/bin/aws', '/usr/bin/aws'];
    }

    /** The per-user MSI (AWSCLIV2-User.msi) always installs here, never into binDir. */
    protected function windowsCandidatePaths(): array
    {
        return [rtrim((string) getenv('LOCALAPPDATA'), '/\\').'/Programs/Amazon/AWSCLIV2/aws.exe'];
    }

    /** The per-user MSI's fixed folder, and AWS's own Unix install tree. */
    protected function managedPathMarkers(): array
    {
        return ['/Programs/Amazon/AWSCLIV2/', '/.local/aws-cli/'];
    }

    protected function installOnUnix(): bool
    {
        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        return Process::forever()->run(self::awsInstallCommand($binDir))->exitCode() === 0 && $this->isInstalled();
    }

    /**
     * AWS's own installer takes `--update` over an existing install; on
     * Windows the per-user MSI is simply run again.
     */
    protected function updateManagedCopy(): bool
    {
        if (self::hostOs() === 'windows') {
            return $this->installOnWindows();
        }

        $binDir = self::binDir();
        @mkdir($binDir, 0755, true);

        return $this->runStreaming(self::awsUpdateCommand($binDir)) === 0 && $this->isInstalled();
    }

    /** Re-running AWS's installer with --update rewrites every file, which is a reinstall. */
    protected function reinstallManagedCopy(): bool
    {
        return $this->updateManagedCopy();
    }
}
