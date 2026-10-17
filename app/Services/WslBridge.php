<?php

namespace App\Services;

use App\Traits\ConfiguresWslNetworking;
use Illuminate\Support\Facades\Process;

class WslBridge
{
    use ConfiguresWslNetworking;

    public const string DEFAULT_DISTRO = 'larakube-ubuntu';

    private static ?bool $forceNativeWindows = null;

    /**
     * @var (callable(list<string>, ?string=): int)|null Custom process dispatcher for tests
     */
    private $customDispatcher = null;

    public function __construct(
        private ?bool $nativeWindows = null,
        private ?string $distro = null,
    ) {}

    public static function setForceNativeWindows(?bool $force): void
    {
        self::$forceNativeWindows = $force;
    }

    public static function isNativeWindows(): bool
    {
        return self::$forceNativeWindows ?? (PHP_OS_FAMILY === 'Windows');
    }

    public function isRunningNatively(): bool
    {
        return $this->nativeWindows ?? self::isNativeWindows();
    }

    public function distroName(): string
    {
        return $this->distro ?? (string) (getenv('LARAKUBE_WSL_DISTRO') ?: self::DEFAULT_DISTRO);
    }

    /**
     * Handles wsl.exe UTF-16LE encoding (NUL after every character).
     */
    public function text(string $output): string
    {
        if (str_contains($output, "\0")) {
            $output = (string) mb_convert_encoding($output, 'UTF-8', 'UTF-16LE');
        }

        return str_replace("\u{FEFF}", '', $output);
    }

    public function isWslInstalled(): bool
    {
        return Process::timeout(10)->run(['wsl.exe', '--status'])->successful();
    }

    /**
     * @return list<string>
     */
    public function runningDistros(): array
    {
        $listing = Process::timeout(30)->run(['wsl.exe', '--list', '--running', '--verbose']);

        if (! $listing->successful()) {
            return [];
        }

        $names = [];
        foreach (preg_split('/\R/', $this->text($listing->output())) ?: [] as $line) {
            if (preg_match('/^\s*\*?\s*(\S+)\s+(Running)\s+([12])\s*$/i', $line, $match) === 1) {
                $names[] = $match[1];
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public function otherRunningDistros(?string $distro = null): array
    {
        $target = $distro ?? $this->distroName();

        return array_values(array_filter($this->runningDistros(), fn (string $name): bool => $name !== $target));
    }

    public function distroIsRunning(?string $distro = null): bool
    {
        $target = $distro ?? $this->distroName();

        return in_array($target, $this->runningDistros(), true);
    }

    public function distroExists(?string $distro = null): bool
    {
        $target = strtolower($distro ?? $this->distroName());
        $listing = Process::timeout(30)->run(['wsl.exe', '--list', '--quiet']);

        if (! $listing->successful()) {
            return false;
        }

        $lines = preg_split('/\R/', $this->text($listing->output())) ?: [];
        foreach ($lines as $line) {
            $name = trim($line);
            if ($name !== '' && strtolower($name) === $target) {
                return true;
            }
        }

        return false;
    }

    public function shutdown(): bool
    {
        return Process::timeout(30)->run(['wsl.exe', '--shutdown'])->successful();
    }

    public function restartForNetworkingChange(?string $distro = null): bool
    {
        if ($this->otherRunningDistros($distro) !== []) {
            return false;
        }

        return $this->shutdown();
    }

    public function userProfilePath(): ?string
    {
        $profile = (string) (getenv('USERPROFILE') ?: (getenv('HOMEDRIVE') && getenv('HOMEPATH') ? getenv('HOMEDRIVE').getenv('HOMEPATH') : ''));

        return $profile !== '' ? $profile : null;
    }

    public function wslConfigPath(): ?string
    {
        $profile = $this->userProfilePath();

        return $profile !== null ? rtrim($profile, '\\/').DIRECTORY_SEPARATOR.'.wslconfig' : null;
    }

    public function mirroredNetworkingRequested(): bool
    {
        $path = $this->wslConfigPath();
        if ($path === null || ! is_file($path)) {
            return false;
        }

        $ini = (string) @file_get_contents($path);

        return preg_match('/^[ \t]*networkingMode[ \t]*=[ \t]*mirrored\b/mi', $ini) === 1;
    }

    public function mirroredNetworkingActive(?string $distro = null): bool
    {
        $target = $distro ?? $this->distroName();
        $result = Process::timeout(30)->run(['wsl.exe', '-d', $target, '--', 'wslinfo', '--networking-mode']);

        return strtolower(trim($this->text($result->output()))) === 'mirrored';
    }

    public function mirroredRestartPending(?string $distro = null): bool
    {
        return $this->mirroredNetworkingRequested()
            && ! $this->mirroredNetworkingActive($distro);
    }

    public function ensureMirroredNetworking(): bool
    {
        $path = $this->wslConfigPath();
        if ($path === null) {
            return false;
        }

        $current = is_file($path) ? (string) @file_get_contents($path) : '';
        $newContent = $this->withMirroredNetworking($current);

        return @file_put_contents($path, $newContent) !== false;
    }

    /**
     * Determines whether the given CLI invocation should be forwarded into WSL.
     * Lifecycle management commands (setup, update, wsl:*) remain on the Windows host.
     *
     * @param  list<string>  $argv
     */
    public function shouldDispatchToWsl(array $argv): bool
    {
        if (! $this->isRunningNatively()) {
            return false;
        }

        // Find the command (first non-option argument after script name)
        $command = null;
        $count = count($argv);
        for ($i = 1; $i < $count; $i++) {
            $arg = $argv[$i];
            if (! str_starts_with($arg, '-')) {
                $command = $arg;
                break;
            }
        }

        if ($command === null) {
            // Version check on the host binary
            foreach ($argv as $arg) {
                if ($arg === '--version' || $arg === '-V') {
                    return false;
                }
            }

            // If no command is provided, forward to WSL if distro exists so full help/list is displayed
            return $this->distroExists();
        }

        // Native lifecycle commands run outside WSL
        if (in_array($command, ['setup', 'update'], true) || str_starts_with($command, 'wsl:')) {
            return false;
        }

        return true;
    }

    /**
     * Allows tests to intercept process execution.
     *
     * @param  (callable(list<string>, ?string=): int)|null  $dispatcher
     */
    public function setCustomDispatcher(?callable $dispatcher): void
    {
        $this->customDispatcher = $dispatcher;
    }

    /**
     * Dispatches command invocation to WSL2 distro.
     *
     * @param  list<string>  $argv
     */
    public function dispatchToWsl(array $argv, ?string $cwd = null): int
    {
        $args = array_slice($argv, 1);
        $command = ['wsl.exe', '-d', $this->distroName(), '--', 'larakube', ...$args];

        if ($this->customDispatcher !== null) {
            return ($this->customDispatcher)($command, $cwd);
        }

        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd);

        if (is_resource($process)) {
            return proc_close($process);
        }

        return 1;
    }
}
